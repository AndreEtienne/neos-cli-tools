<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 2019-01-30
 * Time: 16:13
 */

namespace CRON\NeosCliTools\Service;

use /** @noinspection PhpUnusedAliasInspection */
    Neos\Flow\Annotations as Flow;


use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Image;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\ContentRepository\Domain\Model\Workspace;

/**
 * Content Repository related logic
 *
 * @property string sitePath
 * @property string workspaceName
 * @property Site currentSite
 *
 * @Flow\Scope("singleton")
 */
class CRService
{

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\ContentContextFactory
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Repository\WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\NodeServiceInterface
     */
    protected $nodeService;

    /**
     * @var ContentContext
     */
    public $context;

    /** @var NodeInterface */
    public $rootNode;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * Setup and configure the context to use, take care of the arguments like user name etc.
     *
     * @param string $workspace workspace name, defaults to the live workspace
     *
     * @throws \Exception
     */
    public function setup($workspace = 'live')
    {
        // validate user name, use the live workspace if null
        $this->workspaceName = $workspace;

        /** @noinspection PhpUndefinedMethodInspection */
        if (!$this->workspaceRepository->findByName($this->workspaceName)->count()) {
            throw new \Exception(sprintf('Workspace "%s" is invalid', $this->workspaceName));
        }

        $this->context = $this->contextFactory->create([
            'workspaceName' => $this->workspaceName,
            'currentSite' => $this->currentSite,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);

        $this->rootNode = $this->context->getNode($this->sitePath);
    }

    /**
     * @param NodeInterface $document
     * @param $url
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getNodePathForURL(NodeInterface $document, $url) {
        $parts = explode('/', $url);
        foreach ($parts as $segment) {
            if (!$segment) { continue; }
            $document = $this->getChildDocumentByURIPathSegment($document, $segment);
        }

        return $document->getPath();
    }

    /**
     * @param NodeInterface $document
     * @param $pathSegment
     *
     * @return NodeInterface
     * @throws \Exception
     */
    private function getChildDocumentByURIPathSegment(NodeInterface $document, $pathSegment) {
        $found = array_filter($document->getChildNodes('Neos.Neos:Document'),
            function (NodeInterface $document) use ($pathSegment ){
                return $document->getProperty('uriPathSegment') === $pathSegment;
            }
        );

        if (count($found) === 0) {
            throw new \Exception(sprintf('Could not find any child document for URL path segment: "%s" on "%s',
                $pathSegment,
                $document->getPath()
            ));
        }
        return array_pop($found);
    }

    /**
     * Fetches the associated NoteType object for the specified node type
     *
     * @param string $type NodeType name, e.g. 'YPO3.Neos.NodeTypes:Page'
     *
     * @return \Neos\ContentRepository\Domain\Model\NodeType
     *
     * @throws \Exception
     */
    public function getNodeType($type) {
        if (!$this->nodeTypeManager->hasNodeType($type)) {
            throw new \Exception('specified node type is not valid');
        }

        return $this->nodeTypeManager->getNodeType($type);
    }

    /**
     * Sets the node properties
     *
     * @param NodeInterface $node
     * @param string $propertiesJSON JSON string of node properties
     *
     * @throws \Exception
     */
    public function setNodeProperties($node, $propertiesJSON)
    {
        $data = json_decode($propertiesJSON, true);

        if ($data === null) {
            throw new \Exception('could not decode JSON data');
        }

        foreach ($data as $name => $value) {
            $value = $this->propertyMapper($node, $name, $value);
            $node->setProperty($name, $value);
        }
    }

    /**
     * Fetches an existing node by URL
     *
     * @param string $url URL of the node, e.g. '/news/my-news'
     *
     * @return NodeInterface
     * @throws \Exception
     */
    public function getNodeForURL($url)
    {
        return $this->context->getNode($this->getNodePathForURL($this->rootNode, $url));
    }

    /**
     * Fetches an existing node by relative path
     *
     * @param string $path relative path of the page
     *
     * @return NodeInterface
     * @throws \Exception
     */
    public function getNodeForPath($path)
    {
        return $this->context->getNode($this->sitePath . $path);
    }

    /**
     * Publishes the configured workspace
     *
     * @throws \Exception
     */
    public function publish()
    {
        $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
        if (!$liveWorkspace) {
            throw new \Exception('Could not find the live workspace.');
        }
        /** @var Workspace $liveWorkspace */
        $this->context->getWorkspace()->publish($liveWorkspace);
    }

    /**
     * @param NodeInterface $parentNode
     * @param string $idealNodeName
     *
     * @return string
     */
    public function generateUniqNodeName($parentNode, $idealNodeName = null) {
        return $this->nodeService->generateUniqueNodeName($parentNode->getPath(), $idealNodeName);
    }

    /**
     * @throws \Exception
     */
    public function initializeObject()
    {
        /** @var Site $currentSite */
        $currentSite = $this->siteRepository->findFirstOnline();
        if (!$currentSite) {
            throw new \Exception('No site found');
        }
        $this->sitePath = '/sites/' . $currentSite->getNodeName();
        $this->currentSite = $currentSite;
    }

    /**
     * Map a String Value to the corresponding Neos Object
     *
     * @param $node NodeInterface
     * @param $propertyName string
     * @param $stringInput string
     *
     * @return mixed
     * @throws \Exception
     */
    protected function propertyMapper($node, $propertyName, $stringInput)
    {

        if ($stringInput === 'NULL') {
            return null;
        }

        switch ($node->getNodeType()->getConfiguration('properties.' . $propertyName . '.type')) {

            case 'references':
                $value = array_map(function ($path) { return $this->getNodeForPath($path); },
                    preg_split('/,\w*/', $stringInput));
                break;

            case 'reference':
                $value = $this->getNodeForPath($stringInput);
                break;

            case 'DateTime':
                $value = new \DateTime($stringInput);
                break;

            case 'integer':
                $value = intval($stringInput);
                break;

            case 'boolean':
                $value = boolval($stringInput);
                break;

            case \Neos\Media\Domain\Model\ImageInterface::class:
                $value = new Image($this->resourceManager->importResource($stringInput));
                break;

            default:
                $value = $stringInput;
                break;
        }

        return $value;
    }

}
