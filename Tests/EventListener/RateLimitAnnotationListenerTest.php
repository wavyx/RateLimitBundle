<?php

namespace Noxlogic\RateLimitBundle\EventListener\Tests;

use Noxlogic\RateLimitBundle\Annotation\RateLimit;
use Noxlogic\RateLimitBundle\EventListener\RateLimitAnnotationListener;
use Noxlogic\RateLimitBundle\Service\RateLimitService;
use Noxlogic\RateLimitBundle\Tests\EventListener\MockStorage;
use Noxlogic\RateLimitBundle\Tests\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class MockController {
    function mockAction() { }
}


class RateLimitAnnotationListenerTest extends TestCase
{

    /**
     * @var MockStorage
     */
    protected $mockStorage;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $mockPathLimitProcessor;

    protected function setUp()
    {
        $this->mockStorage = new MockStorage();
    }

    protected function getMockStorage()
    {
        return $this->mockStorage;
    }


    public function testReturnedWhenNotAMasterRequest()
    {
        $listener = $this->createListener($this->never());

        $event = $this->createEvent(HttpKernelInterface::SUB_REQUEST);
        $listener->onKernelController($event);
    }


    public function testReturnedWhenNoControllerFound()
    {
        $listener = $this->createListener($this->never());

        $kernel = $this->getMock('Symfony\\Component\\HttpKernel\\HttpKernelInterface');
        $request = new Request();
        $event = new FilterControllerEvent($kernel, function() {}, $request, HttpKernelInterface::MASTER_REQUEST);

        $listener->onKernelController($event);
    }


    public function testReturnedWhenNoAnnotationsFound()
    {
        $listener = $this->createListener($this->never());

        $event = $this->createEvent();
        $listener->onKernelController($event);
    }

    public function testDelegatesToPathLimitProcessorWhenNoAnnotationsFound()
    {
        $request = new Request();
        $event = $this->createEvent(HttpKernelInterface::MASTER_REQUEST, $request);

        $listener = $this->createListener($this->never());

        $this->mockPathLimitProcessor->expects($this->once())
                                     ->method('getRateLimit')
                                     ->with($request);

        $listener->onKernelController($event);
    }

    public function testDispatchIsCalled()
    {
        $listener = $this->createListener($this->once());

        $event = $this->createEvent();
        $event->getRequest()->attributes->set('_x-rate-limit', array(
            new RateLimit(array('limit' => 100, 'period' => 3600)),
        ));

        $listener->onKernelController($event);
    }

    public function testDispatchIsCalledIfThePathLimitProcessorReturnsARateLimit()
    {
        $event = $this->createEvent(HttpKernelInterface::MASTER_REQUEST);

        $listener = $this->createListener($this->once());

        $rateLimit = new RateLimit(array(
            'limit' => 100,
            'period' => 200
        ));

        $this->mockPathLimitProcessor->expects($this->any())
                                     ->method('getRateLimit')
                                     ->will($this->returnValue($rateLimit));

        $listener->onKernelController($event);
    }

    public function testIsRateLimitSetInRequest()
    {
        $listener = $this->createListener($this->any());

        $event = $this->createEvent();
        $event->getRequest()->attributes->set('_x-rate-limit', array(
            new RateLimit(array('limit' => 5, 'period' => 10)),
        ));


        $this->assertNull($event->getRequest()->attributes->get('rate_limit_info'));

        // Create initial ratelimit in storage
        $listener->onKernelController($event);
        $this->assertArrayHasKey('rate_limit_info', $event->getRequest()->attributes->all());

        // Add second ratelimit in storage
        $listener->onKernelController($event);
        $this->assertArrayHasKey('rate_limit_info', $event->getRequest()->attributes->all());
    }

    public function testRateLimit()
    {
        $listener = $this->createListener($this->any());

        $event = $this->createEvent();
        $event->getRequest()->attributes->set('_x-rate-limit', array(
            new RateLimit(array('limit' => 5, 'period' => 5)),
        ));

        $listener->onKernelController($event);
        $this->assertInternalType('array', $event->getController());
        $listener->onKernelController($event);
        $this->assertInternalType('array', $event->getController());
        $listener->onKernelController($event);
        $this->assertInternalType('array', $event->getController());
        $listener->onKernelController($event);
        $this->assertInternalType('array', $event->getController());
        $listener->onKernelController($event);
        $this->assertInternalType('array', $event->getController());
        $listener->onKernelController($event);
        $this->assertNotInternalType('array', $event->getController());
        $listener->onKernelController($event);
        $this->assertNotInternalType('array', $event->getController());
        $listener->onKernelController($event);
        $this->assertNotInternalType('array', $event->getController());
    }

    public function testRateLimitThrottling()
    {
        $listener = $this->createListener($this->any());

        $event = $this->createEvent();
        $event->getRequest()->attributes->set('_x-rate-limit', array(
                new RateLimit(array('limit' => 5, 'period' => 3)),
        ));

        // Throttled
        $storage = $this->getMockStorage();
        $storage->createMockRate(':Noxlogic\RateLimitBundle\EventListener\Tests\MockController:mockAction', 5, 10, 6);
        $listener->onKernelController($event);
        $this->assertNotInternalType('array', $event->getController());
    }

    public function testRateLimitExpiring()
    {
        $listener = $this->createListener($this->any());

        $event = $this->createEvent();
        $event->getRequest()->attributes->set('_x-rate-limit', array(
            new RateLimit(array('limit' => 5, 'period' => 3)),
        ));

        // Expired
        $storage = $this->getMockStorage();
        $storage->createMockRate(':Noxlogic\RateLimitBundle\EventListener\Tests\MockController:mockAction', 5, -10, 12);
        $listener->onKernelController($event);
        $this->assertInternalType('array', $event->getController());
    }

    public function testBestMethodMatch()
    {
        $listener = $this->createListener($this->any());
        $method = new ReflectionMethod(get_class($listener), 'findBestMethodMatch');
        $method->setAccessible(true);

        $request = new Request();

        $annotations = array(
            new RateLimit(array('limit' => 100, 'period' => 3600)),
            new RateLimit(array('methods' => 'GET', 'limit' => 100, 'period' => 3600)),
            new RateLimit(array('methods' => array('POST', 'PUT'), 'limit' => 100, 'period' => 3600)),
        );

        // Find the method that matches the string
        $request->setMethod('GET');
        $this->assertEquals(
            $annotations[1],
            $method->invoke($listener, $request, $annotations)
        );

        // Method not found, use the default one
        $request->setMethod('DELETE');
        $this->assertEquals(
            $annotations[0],
            $method->invoke($listener, $request, $annotations)
        );

        // Find best match based in methods in array
        $request->setMethod('PUT');
        $this->assertEquals(
            $annotations[2],
            $method->invoke($listener, $request, $annotations)
        );
    }


    public function testFindNoAnnotations()
    {
        $listener = $this->createListener($this->any());
        $method = new ReflectionMethod(get_class($listener), 'findBestMethodMatch');
        $method->setAccessible(true);

        $request = new Request();

        $annotations = array();

        $request->setMethod('PUT');
        $this->assertNull($method->invoke($listener, $request, $annotations));

        $request->setMethod('GET');
        $this->assertNull($method->invoke($listener, $request, $annotations));
    }


    public function testFindBestMethodMatchNotMatchingAnnotations()
    {
        $listener = $this->createListener($this->any());
        $method = new ReflectionMethod(get_class($listener), 'findBestMethodMatch');
        $method->setAccessible(true);

        $request = new Request();

        $annotations = array(
            new RateLimit(array('methods' => 'GET', 'limit' => 100, 'period' => 3600)),
        );

        $request->setMethod('PUT');
        $this->assertNull($method->invoke($listener, $request, $annotations));

        $request->setMethod('GET');
        $this->assertEquals(
            $annotations[0],
            $method->invoke($listener, $request, $annotations)
        );
    }


    public function testFindBestMethodMatchMatchingMultipleAnnotations()
    {
        $listener = $this->createListener($this->any());
        $method = new ReflectionMethod(get_class($listener), 'findBestMethodMatch');
        $method->setAccessible(true);

        $request = new Request();

        $annotations = array(
            new RateLimit(array('methods' => 'GET', 'limit' => 100, 'period' => 3600)),
            new RateLimit(array('methods' => array('GET','PUT'), 'limit' => 200, 'period' => 7200)),
        );

        $request->setMethod('PUT');
        $this->assertEquals($annotations[1], $method->invoke($listener, $request, $annotations));

        $request->setMethod('GET');
        $this->assertEquals($annotations[1], $method->invoke($listener, $request, $annotations));
    }

    /**
     * @return FilterControllerEvent
     */
    protected function createEvent($type = HttpKernelInterface::MASTER_REQUEST, Request $request = null)
    {
        $kernel = $this->getMock('Symfony\\Component\\HttpKernel\\HttpKernelInterface');

        $controller = new MockController();
        $action = 'mockAction';

        $request = $request === null ? new Request() : $request;
        $event = new FilterControllerEvent($kernel, array($controller, $action), $request, $type);
        return $event;
    }


    protected function createListener($expects)
    {
        $mockDispatcher = $this->getMock('Symfony\\Component\\EventDispatcher\\EventDispatcherInterface');
        $mockDispatcher
            ->expects($expects)
            ->method('dispatch');

        $rateLimitService = new RateLimitService();
        $rateLimitService->setStorage($this->getMockStorage());

        $this->mockPathLimitProcessor = $this->getMockBuilder('Noxlogic\RateLimitBundle\Util\PathLimitProcessor')
                                             ->disableOriginalConstructor()
                                             ->getMock();

        return new RateLimitAnnotationListener(
            $mockDispatcher,
            $rateLimitService,
            $this->mockPathLimitProcessor
        );
    }
}
