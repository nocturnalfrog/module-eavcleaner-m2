<?php

namespace Hackathon\EAVCleaner\Console\Command;

use Magento\Catalog\Api\Data\CategoryAttributeInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Eav\Model\ResourceModel\Entity\Type\CollectionFactory as EavEntityTypeCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CleanUpAttributesAndValuesWithoutParentCommand extends Command
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var EavEntityTypeCollectionFactory
     */
    private $eavEntityTypeCollectionFactory;

    public function __construct(
        ResourceConnection $resourceConnection,
        EavEntityTypeCollectionFactory $eavEntityTypeCollectionFactory,
        string $name = null
    ) {
        parent::__construct($name);
        $this->resourceConnection             = $resourceConnection;
        $this->eavEntityTypeCollectionFactory = $eavEntityTypeCollectionFactory;
    }

    protected function configure()
    {
        $description
            = 'Remove catalog_eav_attribute and attribute values which are missing parent entry in eav_attribute';
        $this
            ->setName('eav:clean:attributes-and-values-without-parent')
            ->setDescription($description)
            ->addOption('dry-run');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $isDryRun = $input->getOption('dry-run');

        if (!$isDryRun && $input->isInteractive()) {
            $output->writeln('WARNING: this is not a dry run. If you want to do a dry-run, add --dry-run.');
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);

            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return;
            }
        }

        $db              = $this->resourceConnection->getConnection();
        $types           = ['varchar', 'int', 'decimal', 'text', 'datetime'];
        $entityTypeCodes = [
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            CategoryAttributeInterface::ENTITY_TYPE_CODE,
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER,
            AddressMetadataInterface::ENTITY_TYPE_ADDRESS
        ];
        foreach ($entityTypeCodes as $code) {
            $entityType = $this->eavEntityTypeCollectionFactory
                ->create()
                ->addFieldToFilter('entity_type_code', $code)
                ->getFirstItem();
            $output->writeln("<info>Cleaning values for $code</info>");
            foreach ($types as $type) {
                $eavTable         = $db->getTableName('eav_attribute');
                $entityValueTable = $db->getTableName($code . '_entity_' . $type);
                $query            = "SELECT * FROM $entityValueTable WHERE `attribute_id` NOT IN(SELECT attribute_id"
                    . " FROM `$eavTable` WHERE entity_type_id = " . $entityType->getEntityTypeId() . ")";
                $results          = $db->fetchAll($query);
                $output->writeln("Clean up " . count($results) . " rows in $entityValueTable");

                if (!$isDryRun && count($results) > 0) {
                    $db->query("DELETE FROM $entityValueTable WHERE `attribute_id` NOT IN(SELECT attribute_id"
                        . " FROM `$eavTable` WHERE entity_type_id = " . $entityType->getEntityTypeId() . ")");
                }
            }
        }
    }
}
