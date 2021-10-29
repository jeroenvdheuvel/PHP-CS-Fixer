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
 * @author Miguel Heitor <...>
 *
 * @internal
 *
 * @covers \PhpCsFixer\Fixer\ClassNotation\ConstructorPromotionFixer
 */
final class ConstructorPromotionFixerTest extends AbstractFixerTestCase
{
    /**
     * @dataProvider provideFixCases
     */
    public function testFix(string $expected, ?string $input = null): void
    {
        $this->doTest($expected, $input);
    }

    public function provideFixCases(): array
    {
        return [
//            'empty' => [
//                '',
//            ],
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

    public function __construct($bar) {
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

    public function __construct($bar) {
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

    public function __construct($bar) {
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

    public function __construct($bar, $baz) {
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
                ]
        ];
    }
}
