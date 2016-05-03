<?php
namespace FS\SolrBundle\Command;

use Doctrine\Common\Persistence\AbstractManagerRegistry;
use FS\SolrBundle\Console\ConsoleErrorListOutput;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command synchronizes the DB with solr
 */
class SynchronizeIndexCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('solr:index:populate')
            ->addArgument('entity', InputArgument::OPTIONAL, 'The entity you want to index', null)
            ->addArgument('flushsize', InputArgument::OPTIONAL, 'Number of items to handle before flushing data', 500)
            ->addOption(
                'source',
                null,
                InputArgument::OPTIONAL,
                'specify a source from where to load entities [relational, mongodb]',
                'relational'
            )
            ->setDescription('Index all entities');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $entities = $this->getIndexableEntities($input->getArgument('entity'));
        $source = $input->getOption('source');
        $batchSize = $input->getArgument('flushsize');
        $solr = $this->getContainer()->get('solr.client');

        $objectManager = $this->getObjectManager($source);

        foreach ($entities as $entityCollection) {
            $output->writeln(sprintf('Indexing: <info>%s</info>', $entityCollection));

            try {
                $repository = $objectManager->getRepository($entityCollection);
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>No repository found for "%s", check your input</error>', $entityCollection));

                continue;
            }

            $totalSize = $this->getTotalNumberOfEntities($entityCollection, $source);

            if ($totalSize === 0) {
                $output->writeln('<comment>No entities found for indexing</comment>');

                continue;
            }

            $metaInformation = $solr->getMetaFactory()->loadInformation($entityCollection);

            $output->writeln(sprintf('Synchronize <info>%s</info> entities', $totalSize));
            $output->writeln(sprintf('Use index <info>%s</info>', $metaInformation->getIndex()));
            $output->writeln('');

            $progress = new ProgressBar($output, $totalSize);
            $progress->start();

            $batchLoops = ceil($totalSize / $batchSize);

            for ($i = 0; $i <= $batchLoops; $i++) {
                $entities = $repository->findBy(array(), null, $batchSize, $i * $batchSize);
                foreach ($entities as $entity) {
                    try {
                        $solr->synchronizeIndex($entity);
                        $progress->advance();
                    } catch (\Exception $e) {
                    }
                }
            }

            $progress->finish();
            $output->writeln('');
            $output->writeln('');

            $results = $this->getContainer()->get('solr.console.command.results');
            if ($results->hasErrors()) {
                $output->writeln('<info>Synchronization finished with errors!</info>');
            } else {
                $output->writeln('<info>Synchronization successful</info>');
            }

            $output->writeln('');
            $output->writeln(sprintf('Synchronized Documents: <info>%s</info>', $results->getSucceed()));
            $output->writeln(sprintf('Not Synchronized Documents: <info>%s</info>', $results->getErrored()));
            $output->writeln('');

            if ($results->hasErrors()) {
                $errorList = new ConsoleErrorListOutput($output, $this->getHelper('table'), $results->getErrors());
                $errorList->render();
            }
        }
    }

    /**
     * @param string $source
     *
     * @throws \InvalidArgumentException if $source is unknown
     * @throws \RuntimeException if no doctrine instance is configured
     *
     * @return AbstractManagerRegistry
     */
    private function getObjectManager($source)
    {
        $objectManager = null;

        if ($source === 'relational') {
            $objectManager = $this->getContainer()->get('doctrine');
        } else {
            if ($source === 'mongodb') {
                $objectManager = $this->getContainer()->get('doctrine_mongodb');
            } else {
                throw new \InvalidArgumentException(sprintf('Unknown source %s', $source));
            }
        }

        return $objectManager;
    }

    /**
     * Get a list of entities which are indexable by Solr
     *
     * @param null|string $entity
     * @return array
     */
    private function getIndexableEntities($entity = null)
    {
        if ($entity) {
            return array($entity);
        }

        $entities = array();
        $namespaces = $this->getContainer()->get('solr.doctrine.classnameresolver.known_entity_namespaces');
        $metaInformationFactory = $this->getContainer()->get('solr.meta.information.factory');

        foreach ($namespaces->getEntityClassnames() as $classname) {
            try {
                $metaInformation = $metaInformationFactory->loadInformation($classname);
                array_push($metaInformation->getClassName(), $classname);
            } catch (\RuntimeException $e) {
                continue;
            }
        }

        return $entities;
    }

    /**
     * Get the total number of entities in a repository
     *
     * @param string $entity
     * @param string $source
     *
     * @return int
     * @throws \Exception
     */
    private function getTotalNumberOfEntities($entity, $source)
    {
        $objectManager = $this->getObjectManager($source);
        $repository = $objectManager->getRepository($entity);
        $dataStoreMetadata = $objectManager->getClassMetadata($entity);

        $identifierColumns = $dataStoreMetadata->getIdentifierColumnNames();

        if (!count($identifierColumns)) {
            throw new \Exception(sprintf('No primary key found for entity %s', $entity));
        }

        $countableColumn = reset($identifierColumns);

        $totalSize = $repository->createQueryBuilder('size')
            ->select(sprintf('count(size.%s)', $countableColumn))
            ->getQuery()
            ->getSingleScalarResult();

        return $totalSize;
    }
}
