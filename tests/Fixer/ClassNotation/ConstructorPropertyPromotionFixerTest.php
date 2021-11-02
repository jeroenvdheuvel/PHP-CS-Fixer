<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Tests\Fixer\ClassNotation;

use PhpCsFixer\Tests\Test\AbstractFixerTestCase;

/**
 * @author Jeroen van den Heuvel <jeroenvdheuvel+github@hotmail.com>
 * @author Miguel Heitor <miguelheitor@gmail.com>
 *
 * @internal
 *
 * @covers \PhpCsFixer\Fixer\ClassNotation\ConstructorPropertyPromotionFixer
 */
final class ConstructorPropertyPromotionFixerTest extends AbstractFixerTestCase
{
    /**
     * @dataProvider provideFixCases
     * @requires PHP >=8.0
     */
    public function testFix(string $expected, ?string $input = null): void
    {
        $this->doTest($expected, $input);
    }

    public function provideFixCases(): array
    {
        return [
            'class with public property on top' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(public $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    public $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'class with one protected property on top' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(protected $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    protected $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'class with one private property on top' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(private $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'class with one private property on bottom' => [
                <<<'PHP'
<?php
class Foo {
    public function __construct(private $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    public function __construct($bar) {
        $this->bar = $bar;
    }

    private $bar;
}
PHP,
            ],
            'class with typed property' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(private bool $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private bool $bar;

    public function __construct(bool $bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'class with nullable typed property' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(private ?bool $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private ?bool $bar;

    public function __construct(?bool $bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'class with nullable typed property and null assignment' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(private ?bool $bar = null) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private ?bool $bar = null;

    public function __construct(?bool $bar = null) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'class with multiple properties and assignments' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(private $bar, protected $baz = "baz") {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private $bar;
    protected $baz = "baz";

    public function __construct($bar, $baz = "baz") {
        $this->bar = $bar;
        $this->baz = $baz;
    }
}
PHP,
            ],
            'multiple classes with properties' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(private $bar) {
    }
}

class Bar {

    public function __construct(protected $baz) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}

class Bar {
    protected $baz;

    public function __construct($baz) {
        $this->baz = $baz;
    }
}
PHP,
            ],
            'class property with docblock' => [
                <<<'PHP'
<?php
class Foo {

    public function __construct(private $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    /**
     * @var bool
     */
    private $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
                ],
            'class properties not in the constructor stay in the correct place' => [
                <<<'PHP'
<?php
class Foo {
    private $baz;

    public function __construct(private $bar) {
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private $bar;
    private $baz;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'constructor calling parent stays correct' => [
                <<<'PHP'
<?php
class Bar {}
class Foo extends Bar {

    public function __construct(private $bar) {
        parent::__construct();
    }
}
PHP,
                <<<'PHP'
<?php
class Bar {}
class Foo extends Bar {
    private $bar;

    public function __construct($bar) {
        parent::__construct();
        $this->bar = $bar;
    }
}
PHP,
            ],
            'class with attribute assignment with new stays correct' => [
                <<<'PHP'
<?php
class Foo {
    private $baz;

    public function __construct(private $bar) {
        $this->baz = new DateTimeImmutable();
    }
}
PHP,
                <<<'PHP'
<?php
class Foo {
    private $bar;
    private $baz;

    public function __construct($bar) {
        $this->bar = $bar;
        $this->baz = new DateTimeImmutable();
    }
}
PHP,
            ],
            'invalid class without visibility is unchanged' => [
                <<<'PHP'
<?php
class Foo {
    static $boo;

    public function __construct($boo) {
        $this->boo = $boo;
    }
}
PHP,
            ],
            'variadic properties are not promoted' => [
                    <<<'PHP'
<?php
class Foo
{
    private array $bar;

    public function __construct(int ...$bar)
    {
        $this->bar = $bar;
    }
}
PHP,
                ],
                'class with array typed property with default' => [
                    <<<'PHP'
<?php
class Foo
{

    public function __construct(private array $bar = [])
    {
    }
}
PHP,
                    <<<'PHP'
<?php
class Foo
{
    private array $bar;

    public function __construct(array $bar = [])
    {
        $this->bar = $bar;
    }
}
PHP,
                ],
                'class with typed property from global namespace' => [
                    <<<'PHP'
<?php
class Foo
{

    public function __construct(private \DateTimeImmutable $bar)
    {
    }
}
PHP,
                    <<<'PHP'
<?php
class Foo
{
    private \DateTimeImmutable $bar;

    public function __construct(\DateTimeImmutable $bar)
    {
        $this->bar = $bar;
    }
}
PHP,
                ],
                'class with typed property from namespace' => [
                    <<<'PHP'
<?php
class Foo
{

    public function __construct(private \Foo\Bar $bar)
    {
    }
}
PHP,
                    <<<'PHP'
<?php
class Foo
{
    private \Foo\Bar $bar;

    public function __construct(\Foo\Bar $bar)
    {
        $this->bar = $bar;
    }
}
PHP,
                ],
                'trait with constructor' => [
                    <<<'PHP'
<?php
trait Foo {

    public function __construct(private $bar) {
    }
}
PHP,
                    <<<'PHP'
<?php
trait Foo {
    private $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
                ],
            'abstract classes should not promote' => [
                <<<'PHP'
<?php
abstract class Foo {
    private $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
            'var should not promote' => [
                <<<'PHP'
<?php
abstract class Foo {
    var $bar;

    public function __construct($bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
                'class with not matching types does not change' => [
                    <<<'PHP'
<?php
class Foo {
    private bool $bar;
    private string $baz;

    public function __construct($bar, int $baz) {
        $this->bar = $bar;
        $this->baz = $baz;
    }
}
PHP,
            ],
            'class without constructor does not change' => [ // TODO: This fails in some kind of way
                <<<'PHP'
<?php
class Foo {
    private bool $bar;

    public function baz(): void {}
}
PHP,
            ],
            'class with callable argument does not change' => [
                <<<'PHP'
<?php
class Foo {
    private $bar;

    public function __construct(callable $bar) {
        $this->bar = $bar;
    }
}
PHP,
            ],
        ];
    }
}
