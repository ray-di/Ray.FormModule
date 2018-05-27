<?php
/**
 * This file is part of the Ray.WebFormModule package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace Ray\WebFormModule;

use Doctrine\Common\Annotations\AnnotationReader;
use Ray\Aop\Arguments;
use Ray\Aop\ReflectiveMethodInvocation;
use Ray\WebFormModule\Exception\InvalidFormPropertyException;
use Ray\WebFormModule\Exception\InvalidOnFailureMethod;
use Ray\WebFormModule\Exception\ValidationException;

class AuraInputInterceptorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ReflectiveMethodInvocation
     */
    private $methodInvocation;

    public function setUp()
    {
        parent::setUp();
    }

    /**
     * @param $method
     */
    public function getMethodInvocation($method, array $submit, FailureHandlerInterface $handler = null)
    {
        $handler = $handler ?: new OnFailureMethodHandler;
        $object = $this->getController($submit);

        return new ReflectiveMethodInvocation(
            $object,
            new \ReflectionMethod($object, $method),
            $submit,
            [
                new AuraInputInterceptor(new AnnotationReader, $handler)
            ]
        );
    }

    public function getController(array $submit)
    {
        $controller = new FakeController;
        /** @var $fakeForm FakeForm */
        $fakeForm = (new FormFactory)->newInstance(FakeForm::class);
        $fakeForm->setSubmit($submit);
        $controller->setForm($fakeForm);

        return $controller;
    }

    public function proceed($controller)
    {
        $invocation = new ReflectiveMethodInvocation(
            $controller,
            new \ReflectionMethod($controller, 'createAction'),
            [],
            [
                new AuraInputInterceptor(new AnnotationReader, new OnFailureMethodHandler)
            ]
        );
        $invocation->proceed();
    }

    public function testProceedFailed()
    {
        $invocation = $this->getMethodInvocation('createAction', []);
        $result = $invocation->proceed();
        $this->assertSame('400', $result);
    }

    public function testProceed()
    {
        $invocation = $this->getMethodInvocation('createAction', ['BEAR']);
        $result = $invocation->proceed();
        $this->assertSame('201', $result);
    }

    public function invalidControllerProvider()
    {
        return [
            [new FakeInvalidController1],
            [new FakeInvalidController2]
        ];
    }

    /**
     * @dataProvider invalidControllerProvider
     *
     * @param $controller
     */
    public function testInvalidFormPropertyByMissingProperty($controller)
    {
        $this->setExpectedException(InvalidFormPropertyException::class);
        $this->proceed($controller);
    }

    public function testInvalidFormPropertyException()
    {
        $this->setExpectedException(InvalidOnFailureMethod::class);
        $controller = new FakeInvalidController3;
        /** @var $fakeForm FakeForm */
        $fakeForm = (new FormFactory)->newInstance(FakeForm::class);
        $fakeForm->setSubmit(['name' => '']);
        $controller->setForm($fakeForm);
        $this->proceed($controller);
    }

    public function testInvalidFormPropertyByInvalidInstance()
    {
        $this->setExpectedException(InvalidFormPropertyException::class);
        $object = new FakeInvalidController1;
        $invocation = new ReflectiveMethodInvocation(
            $object,
            new \ReflectionMethod($object, 'createAction'),
            ['name' => ''],
            [
                new AuraInputInterceptor(new AnnotationReader, new OnFailureMethodHandler)
            ]
        );
        $invocation->proceed();
    }

    public function testProceedWithVndErrorHandler()
    {
        try {
            $invocation = $this->getMethodInvocation('createAction', [], new VndErrorHandler(new AnnotationReader));
            $invocation->proceed();
        } catch (ValidationException $e) {
            $this->assertInstanceOf(FormValidationError::class, $e->error);
            $json = (string) $e->error;
            $this->assertSame('{
    "message": "Validation failed",
    "path": "",
    "validation_messages": {
        "name": [
            "Name must be alphabetic only."
        ]
    }
}', $json);
        }
    }
}
