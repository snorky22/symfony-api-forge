<?php

namespace Snorky22\ApiForge\Web;

use Snorky22\ApiForge\Dev\Api\Curl\CurlParser;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorLoader;
use Snorky22\ApiForge\Dev\Api\Descriptor\DescriptorStore;
use Snorky22\ApiForge\Dev\Api\Descriptor\ShapeValidator;
use Snorky22\ApiForge\Dev\Api\Example\ExampleReader;
use Snorky22\ApiForge\Dev\Api\Example\PhpArrayExample;
use Snorky22\ApiForge\Dev\Api\Generator\GenerateRunner;
use Snorky22\ApiForge\Dev\Api\Generator\ServiceGenerator;
use Snorky22\ApiForge\Dev\Api\Import\DescriptorMerger;
use Snorky22\ApiForge\Dev\Api\Import\EndpointImporter;
use Snorky22\ApiForge\Dev\Api\Import\ImportRunner;
use Snorky22\ApiForge\Dev\ArrayShapeGenerator;
use Snorky22\ApiForge\Dev\Guide;
use Snorky22\ApiForge\Dev\LenientJsonDecoder;

/**
 * The services of the web interface, built by hand: it runs without the application's container.
 */
final class WebServices
{
    public readonly DescriptorStore $store;
    public readonly ExampleReader $examples;
    public readonly CurlParser $curl;
    public readonly LenientJsonDecoder $decoder;
    public readonly EndpointImporter $importer;
    public readonly ImportRunner $import;
    public readonly GenerateRunner $generate;
    public readonly Guide $guide;

    public function __construct(WebConfig $config)
    {
        $this->store = new DescriptorStore($config->descriptorDir);
        $this->examples = new ExampleReader([new PhpArrayExample()]);
        $this->curl = new CurlParser();
        $this->decoder = new LenientJsonDecoder();
        $this->importer = new EndpointImporter($this->decoder, new ArrayShapeGenerator());
        $importer = $this->importer;
        $loader = new DescriptorLoader($this->store, new ShapeValidator());
        $generator = new ServiceGenerator($config->namespace, $config->sourceDir, $config->configDir);

        $this->import = new ImportRunner($this->curl, $importer, new DescriptorMerger(), $this->store, $this->examples);
        $this->generate = new GenerateRunner($loader, $generator, $config->projectDir, $config->descriptorDir);
        $this->guide = new Guide($loader, $generator, $this->store, $this->curl, $importer, $config->projectDir, $config->descriptorDir, $this->examples);
    }
}
