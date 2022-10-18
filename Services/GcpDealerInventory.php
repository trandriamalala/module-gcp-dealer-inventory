<?php declare(strict_types=1);

namespace BRP\GcpDealerInventory\Services;

use Google\Cloud\PubSub\PubSubClient;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Console\Output\OutputInterface;

class GcpDealerInventory
{
    /** @var Filesystem */
    protected Filesystem $_filesystem;

    /** @var string */
    protected string $_mediaPath;

    /** @var File */
    protected File $_driverFile;

    /** @var Json */
    protected Json $_json;

    /** @var AdapterInterface */
    private AdapterInterface $_connection;

    /** @var ResourceConnection */
    private ResourceConnection $_resource;

    /** @const string */
    const PROJECT_ID = "istinfobus";

    /** @const string */
    const FULL_SUBSCRIPTION_NAME = "OB_DOM_PublishInventoryAvailabilityFullSync-sub";

    /** @const string */
    const DELTA_SUBSCRIPTION_NAME = "OB_DOM_PublishInventoryAvailabilityDeltaSync-sub";

    /** @const int[] */
    const STATUS = [
        'AVAILABLE' => 1
    ];

    /**
     * @param Filesystem $filesystem
     * @param File $driverFile
     * @param Json $json
     * @param ResourceConnection $resource
     */
    public function __construct(
        Filesystem         $filesystem,
        File               $driverFile,
        Json               $json,
        ResourceConnection $resource
    )
    {
        $this->_filesystem = $filesystem;
        $this->_driverFile = $driverFile;
        $this->_json = $json;
        $this->_connection = $resource->getConnection();
        $this->_resource = $resource;
        $this->_mediaPath = $this->_filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath();
        $this->connect();
    }

    /**
     * @return void
     */
    private function connect()
    {
        putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $this->_mediaPath . "google/istinfobus-2ac524c05fdd.json");
    }

    /**
     * @param bool $isFull
     * @param OutputInterface|null $output
     * @return void
     */
    public function pullMessages(bool $isFull, ?OutputInterface $output)
    {
        /**
         * $pubSub = new PubSubClient([
         * 'projectId' => self::PROJECT_ID,
         * ]);
         *
         * if ($isFull) {
         * $subscription = $pubSub->subscription(self::FULL_SUBSCRIPTION_NAME);
         * } else {
         * $subscription = $pubSub->subscription(self::DELTA_SUBSCRIPTION_NAME);
         * }
         *
         * foreach ($subscription->pull() as $message) {
         * //printf('Message: %s' . PHP_EOL, $message->data());
         * $data = $this->_json->unserialize($message->data());
         * $this->processData($data);
         * // Acknowledge the Pub/Sub message has been received, so it will not be pulled multiple times.
         * $subscription->acknowledge($message);
         * }*/
        //Sample
        foreach ($this->getSample() as $message) {
            $this->processData($message, $output);
        }
    }

    /**
     * @param array $data
     * @param OutputInterface|null $output
     * @return void
     */
    private function processData(array $data, ?OutputInterface $output)
    {
        if ($output) {
            $header = $output->section();
            $section = $output->section();
            $header->writeln("transaction_number <info>" . $data['transaction_number'] . "</info>");
        }

        if (isset($data['items'])) {
            $toInsert = [];
            $toUpdate = [];
            foreach ($data['items'] as $item) {
                $dealerId = $this->dealerIdByCustomerNo($item['customer_no']);
                $productId = $this->productIdBySku($item['product_code']);

                if ($productId > 0 && $dealerId > 0) {
                    $checkIfNewData = $this->checkIfNewData($dealerId, $item['product_code']);
                    if ($output) {
                        $section->overwrite("<info>" . $item['product_code'] . "</info> stock for dealer : <info>" . $item['customer_no'] . "</info>");
                    }
                    $data = [
                        'dealer_id' => $dealerId,
                        'product_sku' => $item['product_code'],
                        'qty' => $item['qty_on_hand'],
                        'stock_status' => self::STATUS[$item['status']]
                    ];
                    $inventoryDate = date('Y-m-d h:i:s', strtotime($item['Inventory_date']));
                    //$data['updated_at'] = $inventoryDate;
                    $data['updated_at'] = date('Y-m-d h:i:s');

                    if ($checkIfNewData) {
                        $data['created_at'] = $inventoryDate;
                        $toInsert[] = $data;
                    } else {
                        $toUpdate[] = $data;
                    }
                } elseif ($productId == 0 || $dealerId == 0) {
                    //ADD LOG HERE
                    if ($output && $productId == 0) {
                        $section->overwrite("<error>" . $item['product_code'] . "</error> stock for dealer : <info>" . $item['customer_no'] . "</info>");
                    } elseif ($output && $dealerId == 0) {
                        $section->overwrite("<info>" . $item['product_code'] . "</info> stock for dealer : <error>" . $item['customer_no'] . "</error>");
                    }
                }
            }

            if (!empty($toInsert)) {
                $this->insertDealersInventory($toInsert);
            }

            if (!empty($toUpdate)) {
                $this->updateDealersInventory($toUpdate);
            }
        }
    }

    /**
     * @param $toUpdate
     * @return void
     */
    public function updateDealersInventory($toUpdate)
    {
        $this->_connection->beginTransaction();
        foreach ($toUpdate as $item) {
            $data = [
                'qty' => $item['qty'] + rand(0, 50),
                'updated_at' => $item['updated_at']
            ];

            $where = [
                'dealer_id = ?' => (int)$item['dealer_id'],
                'product_sku = ?' => $item['product_sku']
            ];
            $tableName = $this->_connection->getTableName($this->_resource->getTableName('dealer_stock_status'));
            $this->_connection->update($tableName, $data, $where);
        }
        $this->_connection->commit();
    }

    /**
     * @param $inventory
     * @return void
     */
    public function insertDealersInventory($inventory)
    {
        $tableName = $this->_resource->getTableName('dealer_stock_status');
        $this->_connection->beginTransaction();
        $this->_connection->insertOnDuplicate($tableName, $inventory);
        $this->_connection->commit();
        return;
    }

    /**
     * @param int $dealerId
     * @param string $productSku
     * @return bool
     */
    public function checkIfNewData(int $dealerId, string $productSku): bool
    {
        $select = $this->_connection->select()->from(
            ['i' => $this->_resource->getTableName('dealer_stock_status')],
            ['*']
        )->where("i.dealer_id = ?", $dealerId)
            ->where("i.product_sku = ?", $productSku);

        return (int)$this->_connection->fetchOne($select) == 0;
    }

    /**
     * @param string $sku
     * @return int
     */
    public function productIdBySku(string $sku): int
    {
        $select = $this->_connection->select()->from(
            ['p' => $this->_resource->getTableName('catalog_product_entity')],
            ['*']
        )->where("p.sku = ?", $sku);

        return (int)$this->_connection->fetchOne($select);
    }

    /**
     * @param string $_customerNo
     * @return int
     */
    public function dealerIdByCustomerNo(string $_customerNo): int
    {
        $select = $this->_connection->select()->from(
            ['d' => $this->_resource->getTableName('dealer')],
            ['*']
        )->where("d.customer_no = ?", $_customerNo);

        return (int)$this->_connection->fetchOne($select);
    }

    /************************************************************************************
     ***************************** TO REMOVE, JUST FOR TEST *****************************
     ************************************************************************************/

    /**
     * @return array
     */
    public function getSample(): ?array
    {
        try {
            $sampleData = $this->_mediaPath . "google/demo.json";
            $contents = $this->_driverFile->fileGetContents($sampleData);
            $data = $this->_json->unserialize($contents);
        } catch (\Magento\Framework\Exception\FileSystemException  $e) {
            // var_dump($e->getMessage());
            $data = null;
        }
        return $data;
    }

    /************************************************************************************
     *************************** END TO REMOVE, JUST FOR TEST ***************************
     ************************************************************************************/
}