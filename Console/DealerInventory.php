<?php declare(strict_types=1);

namespace ThirdParty\GcpDealerInventory\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use BRP\GcpDealerInventory\Services\GcpDealerInventory;

class DealerInventory extends Command
{
    /** @const string */
    const IS_FULL = 'is_full'; // key of parameter

    /** @var GcpDealerInventory */
    protected GcpDealerInventory $_gcpDealerInventory;

    /**
     * @param GcpDealerInventory $gcpDealerInventory
     * @param string|null $name
     */
    public function __construct(
        GcpDealerInventory $gcpDealerInventory,
        string     $name = null
    )
    {
        $this->_gcpDealerInventory = $gcpDealerInventory;
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $options = [
            new InputOption(
                self::IS_FULL, // the option name
                '-f', // the shortcut
                InputOption::VALUE_OPTIONAL, // the option mode
                'Check if delta or full import' // the description
            ),
        ];

        $this->setName('brp:dealer:inventory');
        $this->setDescription('Demo command line');
        $this->setDefinition($options);
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $isFull = filter_var($input->getOption(self::IS_FULL), FILTER_VALIDATE_BOOLEAN);
        $output->writeln("Google Cloud Platform PUB/SUB : BRP inventory");
        $this->_gcpDealerInventory->pullMessages($isFull, $output);
    }
}