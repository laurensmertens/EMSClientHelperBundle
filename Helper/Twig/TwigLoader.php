<?php

namespace EMS\ClientHelperBundle\Helper\Twig;

use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequest;
use Twig\Loader\LoaderInterface;

/**
 * Defined for each elasticms config with the option 'templates'.
 *
 * @see EMSClientHelperExtension::defineTwigLoader()
 */
class TwigLoader implements LoaderInterface
{
    /**
     * @var ClientRequest
     */
    private $client;

    /**
     * @var array
     */
    private $config;

    const PREFIX = '@EMSCH';

    public function __construct(ClientRequest $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceContext($name)
    {
        return new \Twig_Source($this->getTemplate($name)['code'], $name);
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated used for php < 7
     */
    public function getSource($name)
    {
        return $this->getTemplate($name)['code'];
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheKey($name)
    {
        return $this->client->getCacheKey('twig_').$name;
    }

    /**
     * {@inheritdoc}
     */
    public function isFresh($name, $time)
    {
        $matches = $this->match($name);
        $contentType = \array_shift($matches);

        return $this->client->getLastChangeDate($contentType)->getTimestamp() <= $time;
    }

    /**
     * {@inheritdoc}
     */
    public function exists($name): bool
    {
        return self::PREFIX === \substr($name, 0, 6);
    }

    /**
     * @param string $name
     *
     * @return array
     */
    private function getTemplate($name)
    {
        $match = $this->match($name);
        list($contentType, $searchValue, $searchTerm) = $match;

        if (!isset($this->config[$contentType])) {
            throw new TwigException('Missing config EMSCH_TEMPLATES');
        }

        $config = $this->config[$contentType];
        $searchTerm = $searchTerm ?: $config['name'];

        return $this->search($contentType, $searchValue, $searchTerm, $config['code']);
    }

    /**
     * @param string $name
     *
     * @return array
     */
    private function match($name)
    {
        \preg_match('/^@EMSCH\/(?<content_type>[a-z][a-z0-9\-_]*):(?<search_val>.*)$/', $name, $matchOuuid);

        if ($matchOuuid) {
            return [$matchOuuid['content_type'], $matchOuuid['search_val'], '_id'];
        }

        \preg_match('/^@EMSCH\/(?<content_type>[a-z][a-z0-9\-_]*)\/(?<search_val>.*)$/', $name, $matchName);

        if ($matchName) {
            return [$matchName['content_type'], $matchName['search_val'], null];
        }

        throw new TwigException(\sprintf('Invalid template name: %s', $name));
    }

    /**
     * @param string $contentType
     * @param string $searchVal   ouuid, templateName
     * @param string $searchTerm  _id, key, name
     * @param string $code        code field in document
     *
     * @return array
     */
    private function search($contentType, $searchVal, $searchTerm, $code)
    {
        try {
            $document = $this->client->searchOne($contentType, [
                'query' => [
                    'term' => [
                        $searchTerm => $searchVal,
                    ],
                ],
                '_source' => [$code, '_published_datetime', '_finalization_datetime'],
            ]);

            $source = $document['_source'];

            $date = $this->client->getLastChangeDate($contentType);

            return ['fresh_time' => $date->getTimestamp(), 'code' => $source[$code]];
        } catch (\Exception $e) {
            throw new TwigException(\sprintf('Template not found %s:%s', $contentType, $searchVal));
        }
    }
}
