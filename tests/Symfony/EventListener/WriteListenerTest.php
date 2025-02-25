<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Tests\Symfony\EventListener;

use ApiPlatform\Api\IriConverterInterface;
use ApiPlatform\Core\Api\ResourceClassResolverInterface;
use ApiPlatform\Core\Tests\ProphecyTrait;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Symfony\EventListener\WriteListener;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\AttributeResource;
use ApiPlatform\Tests\Fixtures\TestBundle\Entity\OperationResource;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class WriteListenerTest extends TestCase
{
    use ProphecyTrait;

    private $processorProphecy;
    private $iriConverterProphecy;
    private $resourceMetadataCollectionFactory;
    private $resourceClassResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processorProphecy = $this->prophesize(ProcessorInterface::class);
        $this->iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $this->resourceMetadataCollectionFactory = $this->prophesize(ResourceMetadataCollectionFactoryInterface::class);
        $this->resourceClassResolver = $this->prophesize(ResourceClassResolverInterface::class);
    }

    /**
     * @requires PHP 8.0
     */
    public function testOnKernelViewWithControllerResultAndPersist()
    {
        $operationResource = new OperationResource(1, 'foo');

        $this->processorProphecy->supports($operationResource, [], Argument::type('string'), Argument::type('array'))->willReturn(true);
        $this->processorProphecy->process($operationResource, Argument::type('array'), Argument::type('string'), Argument::type('array'))->willReturn($operationResource)->shouldBeCalled();

        $this->iriConverterProphecy->getIriFromItem($operationResource)->willReturn('/operation_resources/1')->shouldBeCalled();
        $this->resourceClassResolver->isResourceClass(Argument::type('string'))->willReturn(true);

        $operationResourceMetadata = new ResourceMetadataCollection(OperationResource::class, [(new ApiResource())->withOperations(new Operations([
            '_api_OperationResource_patch' => (new Patch())->withName('_api_OperationResource_patch'),
            '_api_OperationResource_put' => (new Put())->withName('_api_OperationResource_put'),
            '_api_OperationResource_post_collection' => (new Post())->withName('_api_OperationResource_post_collection'),
        ]))]);

        $this->resourceMetadataCollectionFactory->create(OperationResource::class)->willReturn($operationResourceMetadata);

        $request = new Request([], [], ['_api_resource_class' => OperationResource::class]);

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $operationResource
        );

        foreach (['PATCH', 'PUT', 'POST'] as $httpMethod) {
            $request->setMethod($httpMethod);
            $request->attributes->set('_api_operation_name', sprintf('_api_%s_%s%s', 'OperationResource', strtolower($httpMethod), 'POST' === $httpMethod ? '_collection' : ''));

            (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
            $this->assertSame($operationResource, $event->getControllerResult());
            $this->assertEquals('/operation_resources/1', $request->attributes->get('_api_write_item_iri'));
        }
    }

    /**
     * @requires PHP 8.0
     */
    public function testOnKernelViewDoNotCallIriConverterWhenOutputClassDisabled()
    {
        $operationResource = new OperationResource(1, 'foo');

        $this->processorProphecy->supports($operationResource, [], 'create_no_output', Argument::type('array'))->willReturn(true);
        $this->processorProphecy->process($operationResource, Argument::type('array'), Argument::type('string'), Argument::type('array'))->willReturn($operationResource)->shouldBeCalled();

        $this->iriConverterProphecy->getIriFromItem($operationResource)->shouldNotBeCalled();
        $this->resourceClassResolver->isResourceClass(Argument::type('string'))->willReturn(true);

        $operationResourceMetadata = new ResourceMetadataCollection(OperationResource::class, [(new ApiResource())->withOperations(new Operations([
            'create_no_output' => (new Post())->withOutput(false)->withName('create_no_output'),
        ]))]);

        $this->resourceMetadataCollectionFactory->create(OperationResource::class)->willReturn($operationResourceMetadata);

        $request = new Request([], [], ['_api_resource_class' => OperationResource::class, '_api_operation_name' => 'create_no_output']);
        $request->setMethod('POST');

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $operationResource
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }

    /**
     * @requires PHP 8.0
     */
    public function testOnKernelViewWithControllerResultAndRemove()
    {
        $operationResource = new OperationResource(1, 'foo');

        $this->processorProphecy->supports($operationResource, ['identifier' => 1], '_api_OperationResource_delete', Argument::type('array'))->willReturn(true);
        $this->processorProphecy->process($operationResource, Argument::type('array'), Argument::type('string'), Argument::type('array'))->willReturn($operationResource)->shouldBeCalled();

        $this->iriConverterProphecy->getIriFromItem($operationResource)->shouldNotBeCalled();

        $operationResourceMetadata = new ResourceMetadataCollection(OperationResource::class, [(new ApiResource())->withOperations(new Operations([
            '_api_OperationResource_delete' => (new Delete())->withName('_api_OperationResource_delete')->withUriVariables(['identifier' => ['class' => OperationResource::class, 'identifiers' => ['identifier']]]),
        ]))]);

        $this->resourceMetadataCollectionFactory->create(OperationResource::class)->willReturn($operationResourceMetadata);

        $request = new Request([], [], ['_api_resource_class' => OperationResource::class, '_api_operation_name' => '_api_OperationResource_delete', 'identifier' => 1]);
        $request->setMethod('DELETE');

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $operationResource
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }

    /**
     * @requires PHP 8.0
     */
    public function testOnKernelViewWithSafeMethod()
    {
        $operationResource = new OperationResource(1, 'foo');

        $this->processorProphecy->supports($operationResource, [], '_api_OperationResource_get', Argument::type('array'))->shouldNotBeCalled();
        $this->processorProphecy->process($operationResource, Argument::type('array'))->shouldNotBeCalled();

        $this->iriConverterProphecy->getIriFromItem($operationResource)->shouldNotBeCalled();

        $operationResourceMetadata = new ResourceMetadataCollection(OperationResource::class, [(new ApiResource())->withOperations(new Operations([
            '_api_OperationResource_get' => (new Get())->withName('_api_OperationResource_get'),
        ]))]);

        $this->resourceMetadataCollectionFactory->create(OperationResource::class)->willReturn($operationResourceMetadata);

        $request = new Request([], [], ['_api_resource_class' => OperationResource::class, '_api_operation_name' => '_api_OperationResource_get']);
        $request->setMethod('GET');

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $operationResource
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }

    /**
     * @requires PHP 8.0
     */
    public function testDoNotWriteWhenControllerResultIsResponse()
    {
        $this->processorProphecy->supports(Argument::cetera())->shouldNotBeCalled();
        $this->processorProphecy->process(Argument::cetera())->shouldNotBeCalled();

        $request = new Request();

        $response = new Response();

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $response
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }

    /**
     * @requires PHP 8.0
     */
    public function testDoNotWriteWhenCant()
    {
        $operationResource = new OperationResource(1, 'foo');

        $this->processorProphecy->supports(Argument::cetera())->shouldNotBeCalled();
        $this->processorProphecy->process(Argument::cetera())->shouldNotBeCalled();

        $operationResourceMetadata = new ResourceMetadataCollection(OperationResource::class, [(new ApiResource())->withOperations(new Operations([
            'create_no_write' => (new Post())->withWrite(false),
        ]))]);

        $this->resourceMetadataCollectionFactory->create(OperationResource::class)->willReturn($operationResourceMetadata);

        $request = new Request([], [], ['_api_resource_class' => OperationResource::class, '_api_operation_name' => 'create_no_write']);
        $request->setMethod('POST');

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $operationResource
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }

    /**
     * @requires PHP 8.0
     */
    public function testOnKernelViewWithNoResourceClass()
    {
        $operationResource = new OperationResource(1, 'foo');

        $this->processorProphecy->supports($operationResource, Argument::type('array'))->shouldNotBeCalled();
        $this->processorProphecy->process($operationResource, Argument::type('array'))->shouldNotBeCalled();

        $iriConverterProphecy = $this->prophesize(IriConverterInterface::class);
        $iriConverterProphecy->getIriFromItem($operationResource)->shouldNotBeCalled();

        $request = new Request();
        $request->setMethod('POST');

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $operationResource
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }

    /**
     * @requires PHP 8.0
     */
    public function testOnKernelViewWithNoProcessorSupport()
    {
        $attributeResource = new AttributeResource(1, 'name');

        $this->processorProphecy->supports($attributeResource, [], 'post', Argument::type('array'))->willReturn(false)->shouldBeCalled();
        $this->processorProphecy->process($attributeResource, Argument::type('array'), Argument::type('string'), Argument::type('array'))->shouldNotBeCalled();

        $this->iriConverterProphecy->getIriFromItem($attributeResource)->shouldNotBeCalled();
        $this->resourceClassResolver->isResourceClass(Argument::type('string'))->shouldNotBeCalled();

        $attributeResourceMetadata = new ResourceMetadataCollection(AttributeResource::class, [(new ApiResource())->withOperations(new Operations([
            'post' => (new Post())->withName('post'),
        ]))]);
        $this->resourceMetadataCollectionFactory->create(AttributeResource::class)->willReturn($attributeResourceMetadata);

        $request = new Request([], [], ['_api_resource_class' => AttributeResource::class, '_api_operation_name' => 'post']);
        $request->setMethod('POST');

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $attributeResource
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }

    /**
     * @requires PHP 8.0
     */
    public function testOnKernelViewInvalidIdentifiers()
    {
        $attributeResource = new AttributeResource(1, 'name');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Invalid identifier value or configuration.');

        $this->processorProphecy->supports($attributeResource, ['slug' => 'test'], '_api_OperationResource_delete', Argument::type('array'))->shouldNotBeCalled();
        $this->processorProphecy->process($attributeResource, Argument::type('array'), Argument::type('string'), Argument::type('array'))->shouldNotBeCalled();

        $this->iriConverterProphecy->getIriFromItem($attributeResource)->shouldNotBeCalled();
        $this->resourceClassResolver->isResourceClass(Argument::type('string'))->shouldNotBeCalled();

        $operationResourceMetadata = new ResourceMetadataCollection(OperationResource::class, [(new ApiResource())->withOperations(new Operations([
            '_api_OperationResource_delete' => (new Delete())->withName('_api_OperationResource_delete')->withUriVariables(['identifier' => ['class' => OperationResource::class, 'identifiers' => ['identifier']]]),
        ]))]);

        $this->resourceMetadataCollectionFactory->create(OperationResource::class)->willReturn($operationResourceMetadata);

        $request = new Request([], [], ['_api_resource_class' => OperationResource::class, '_api_operation_name' => '_api_OperationResource_delete', 'slug' => 'foo']);
        $request->setMethod('DELETE');

        $event = new ViewEvent(
            $this->prophesize(HttpKernelInterface::class)->reveal(),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            $attributeResource
        );

        (new WriteListener($this->processorProphecy->reveal(), $this->iriConverterProphecy->reveal(), $this->resourceMetadataCollectionFactory->reveal(), $this->resourceClassResolver->reveal()))->onKernelView($event);
    }
}
