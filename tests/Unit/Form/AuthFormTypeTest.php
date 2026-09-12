<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ForgotPasswordFormType;
use App\Form\RegistrationFormType;
use App\Form\ResetPasswordFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Unit-Tests fuer die Auth/Form-Types (Password, Forgot, Reset, Registration).
 */
final class AuthFormTypeTest extends TestCase
{
    public function testChangePasswordFormAddsExpectedFields(): void
    {
        $added = [];
        $builder = $this->mockBuilder($added);
        (new ChangePasswordFormType())->buildForm($builder, []);

        self::assertSame(['currentPassword', 'plainPassword', 'submit'], $added);
    }

    public function testChangePasswordFormOptions(): void
    {
        $resolver = new OptionsResolver();
        (new ChangePasswordFormType())->configureOptions($resolver);

        $resolved = $resolver->resolve([]);
        self::assertSame('change_password', $resolved['csrf_token_id']);
    }

    public function testForgotPasswordFormAddsExpectedFields(): void
    {
        $added = [];
        $builder = $this->mockBuilder($added);
        (new ForgotPasswordFormType())->buildForm($builder, []);

        self::assertSame(['email', 'submit'], $added);
    }

    public function testForgotPasswordFormOptions(): void
    {
        $resolver = new OptionsResolver();
        (new ForgotPasswordFormType())->configureOptions($resolver);

        $resolved = $resolver->resolve([]);
        self::assertSame('forgot_password', $resolved['csrf_token_id']);
    }

    public function testResetPasswordFormAddsExpectedFields(): void
    {
        $added = [];
        $builder = $this->mockBuilder($added);
        (new ResetPasswordFormType())->buildForm($builder, []);

        self::assertSame(['plainPassword', 'submit'], $added);
    }

    public function testResetPasswordFormOptions(): void
    {
        $resolver = new OptionsResolver();
        (new ResetPasswordFormType())->configureOptions($resolver);

        $resolved = $resolver->resolve([]);
        self::assertSame('reset_password', $resolved['csrf_token_id']);
    }

    public function testRegistrationFormAddsExpectedFields(): void
    {
        $added = [];
        $builder = $this->mockBuilder($added);
        (new RegistrationFormType())->buildForm($builder, []);

        self::assertSame(['firstName', 'lastName', 'email', 'plainPassword', 'agreeTerms'], $added);
    }

    public function testRegistrationFormOptions(): void
    {
        $resolver = new OptionsResolver();
        (new RegistrationFormType())->configureOptions($resolver);

        $resolved = $resolver->resolve([]);
        self::assertSame(User::class, $resolved['data_class']);
        self::assertSame('registration', $resolved['csrf_token_id']);
    }

    public function testAllFormsImplementFormTypeInterface(): void
    {
        self::assertInstanceOf(FormTypeInterface::class, new ChangePasswordFormType());
        self::assertInstanceOf(FormTypeInterface::class, new ForgotPasswordFormType());
        self::assertInstanceOf(FormTypeInterface::class, new ResetPasswordFormType());
        self::assertInstanceOf(FormTypeInterface::class, new RegistrationFormType());
    }

    private function mockBuilder(array &$added): FormBuilderInterface
    {
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name) use ($builder, &$added): FormBuilderInterface {
            $added[] = $name;

            return $builder;
        });

        return $builder;
    }
}
