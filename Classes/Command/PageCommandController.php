<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 02.05.18
 * Time: 18:58
 */

namespace CRON\NeosCliTools\Command;

use /** @noinspection PhpUnusedAliasInspection */
    Neos\Flow\Annotations as Flow;

use CRON\NeosCliTools\Utility\NeosDocumentTreePrinter;
use CRON\NeosCliTools\Utility\NeosDocumentWalker;
use Neos\Flow\Cli\CommandController;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Class PageCommandController
 *
 * This command controller offers some utilities like remove or batch change of existing Neos pages.
 *
 * The main difference to the existing NodeCommandController is that this one uses high level APIs to manage the underlying
 * nodes and will not use the (low level) doctrine methods to do so. By default it will also use the current workspace
 * of the "admin" user so all changes can be reviewed in the e.g. backend.
 *
 * @package CRON\NeosCliTools\Command
 *
 * @Flow\Scope("singleton")
 */
class PageCommandController extends CommandController
{

    /**
     * @Flow\Inject
     * @var \CRON\NeosCliTools\Service\CRService
     */
    protected $cr;

    /**
     * Shows the current configuration of the working environment
     *
     * @param string $workspace workspace to use, e.g. 'user-admin', defaults to 'live'
     *
     * @throws \Exception
     */
    public function infoCommand($workspace = 'live')
    {
        $this->cr->setup($workspace);

        $this->output->outputTable(
            [
                ['Current Site Name', $this->cr->currentSite->getName()],
                ['Workspace Name', $this->cr->workspaceName],
                ['Site node name', $this->cr->currentSite->getNodeName()],
            ],
            [ 'Key', 'Value']);
    }

    /**
     * Lists all documents, optionally filtered by a prefix
     *
     * @param int $depth depth, defaults to 1
     * @param string $path , e.g. /news (don't use the /sites/dazsite prefix!)
     * @param string $workspace workspace to use, e.g. 'user-admin', defaults to 'live'
     *
     */
    public function listCommand($depth=1, $path = '', $workspace = 'live')
    {
        try {
            $this->cr->setup($workspace);
            $rootNode = $this->cr->getNodeForPath($path);
            if (!$rootNode) {
                throw new \Exception(sprintf('Could not find any node on path "%s"', $path));
            }
            $printer = new NeosDocumentTreePrinter($rootNode, $depth);
            $printer->printTree($this->output);
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }
    }

    /**
     * Remove documents, optionally filtered by a prefix. The unix return code will be 0 (successful) only if at least
     * one document was removed, else it will return 1. Useful for bash while loops.
     *
     * @param string $path , e.g. /news (don't use the /sites/dazsite prefix!)
     * @param string $url use the URL instead of o path
     * @param int $limit limit
     * @param string $workspace workspace to use, e.g. 'user-admin', defaults to 'live'
     *
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    public function removeCommand($path = '', $url = '', $limit = 0, $workspace = 'live')
    {
        try {
            $this->cr->setup($workspace);

            if ($url) {
                $rootNode = $this->cr->getNodeForURL($url);
            } else {
                $rootNode = $this->cr->getNodeForPath($path);
            }

            if (!$rootNode) {
                throw new \Exception(sprintf('Could not find any node on path "%s"', $path));
            }
            $walker = new NeosDocumentWalker($rootNode);

            /** @var NodeInterface[] $nodesToDelete */
            $nodesToDelete = $walker->getNodes($limit);

            foreach ($nodesToDelete as $nodeToDelete) {
                $nodeToDelete->remove();
            }

            $this->output->outputTable(array_map( function(NodeInterface $node) { return [$node]; }, $nodesToDelete),
                ['Deleted Pages']);
            $this->quit(count($nodesToDelete) > 0 ? 0 : 1);

        } catch (\Exception $e) {
            if ($e instanceof \Neos\Flow\Mvc\Exception\StopActionException) { return; }
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
            $this->quit(1);
        }
    }

    /**
     * Publish all pending changes in the workspace
     *
     * @param string $workspace workspace to use, e.g. 'user-admin', defaults to 'live'
     *
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    public function publishCommand($workspace = 'live')
    {
        try {
            $this->cr->setup($workspace);
            $this->cr->publish();
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
            $this->quit(1);
        }
    }

    /**
     * Resolves a given URL to the current Neos node path
     *
     * @param string $url URL to resolve
     * @param string $workspace workspace to use, e.g. 'user-admin', defaults to 'live'
     */
    public function resolveURLCommand($url, $workspace = 'live')
    {
        try {
            $this->cr->setup($workspace);
            /** @var NodeInterface $document */
            $document = $this->cr->getNodeForPath('');
            $this->outputLine('%s', [$this->cr->getNodePathForURL($document, $url)]);
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }
    }

    /**
     * Creates a new page
     *
     * @param string $parentUrl parent of the new page to be created (must exist), e.g. /news
     * @param string $name name of the node, will also be used for the URL segment
     * @param string $type node type, defaults to Neos.Neos.NodeTypes:Page
     * @param string $properties node properties, as JSON, e.g. '{"title":"My Fancy Title"}'
     * @param string $workspace workspace to use, e.g. 'user-admin', defaults to 'live'
     * @param boolean $overwriteExisting whether to overwrite an existing node for that path instead of creating a new one with path segment "node-..."
     */
    public function createCommand($parentUrl, $name, $type = 'Neos.NodeTypes:Page', $properties = null, $workspace = 'live', $overwriteExisting = false)
    {
        try {
            $this->cr->setup($workspace);
            $nodeType = $this->cr->getNodeType($type);
            $parentNode = $this->cr->getNodeForURL($parentUrl);

            $existingNode = $parentNode->getNode($name);

            if ($overwriteExisting && $existingNode) {
                $node = $existingNode;
                $this->outputLine(sprintf('%s already exists, updating properties... .', $node));
            } else {
                $node = $parentNode->createNode($this->cr->generateUniqNodeName($parentNode, $name), $nodeType);
                $this->outputLine(sprintf('%s created.', $node));
            }

            if ($properties) {
                $this->cr->setNodeProperties($node, $properties);
            }
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }

    }

}
