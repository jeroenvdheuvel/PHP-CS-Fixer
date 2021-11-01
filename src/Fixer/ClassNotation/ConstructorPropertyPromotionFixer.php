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

namespace PhpCsFixer\Fixer\ClassNotation;

use InvalidArgumentException;
use RuntimeException;
use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\FunctionsAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;

/**
 * Move class property declaration to the constructor in PHP 8
 *
 * @author Jeroen van den Heuvel <jeroenvdheuvel+github@hotmail.com>
 * @author Miguel Heitor <miguelheitor@gmail.com>
 */
final class ConstructorPropertyPromotionFixer extends AbstractFixer
{
    /**
     * @var Tokens
     */
    private $tokens;

    /**
     * {@inheritdoc}
     */
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Promotes class properties to the constructor.',
            [
                new CodeSample(
                    '<?php
class Foo {
    public bool $bar;
    protected int $baz;
    private $qux;

    public function __construct(bool $bar, int $baz, $qux) {
        $this->bar = $bar;
        $this->baz = $baz;
        $this->qux = $qux;
    }
}

class Foo {

    public function __construct(public bool $bar, protected int $baz, private $qux) {
    }
}
'
                ),
            ]
        );
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $this->tokens = $tokens;
        $classes = $this->extractClassData();

        foreach (array_reverse($classes) as $class) {
            $constructorStart = $this->tokens->getNextTokenOfKind($class['constructor'], ['{']);
            $constructorEnd = $this->tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $constructorStart);

            foreach (array_reverse($class['constructorArguments']) as $name => $index) {
                if (!isset($class['properties'][$name])) {
                    continue;
                }

                $variableAssignmentSequence = $this->extractVariableAssignmentSequence($name, $constructorStart, $constructorEnd);
                if ($variableAssignmentSequence === null) {
                    continue;
                }

                $propertyIndex = $class['properties'][$name];
                $propertyNextMeaningfulTokenIndex = $this->tokens->getNextTokenOfKind($propertyIndex, [T_STRING, ";"]);

                try {
                    $propertyVisibilityIndex = $this->findPropertyVisibilityIndex($propertyIndex);
                } catch (RuntimeException $e) {
                    continue;
                }
                $insertTokens = $this->createPropertyPromotionInsertTokens($propertyVisibilityIndex, $propertyIndex);

                $this->clearVariableAssignment(
                    array_key_first($variableAssignmentSequence),
                    array_key_last($variableAssignmentSequence)
                );

                $this->clearClassProperty($propertyVisibilityIndex, $propertyNextMeaningfulTokenIndex);
                $this->tokens->clearAt($index);
                $this->tokens->insertAt($index, $insertTokens);
            }
        }

        $this->tokens->clearEmptyTokens();
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return \PHP_VERSION_ID >= 80000 && $tokens->isAllTokenKindsFound([T_CLASS, T_FUNCTION, T_VARIABLE]);
    }

    private function extractClassData(): array
    {
        $tokenAnalyzer = new TokensAnalyzer($this->tokens);
        $classyElements = $tokenAnalyzer->getClassyElements();

        $extractedClasses = [];
        foreach ($classyElements as $index => $classyElement) {
            $token = $classyElement['token'];
            $type = $classyElement['type'];
            $classIndex = $classyElement['classIndex'];

            if ($type === 'property') {
                $extractedClasses[$classIndex]['properties'][$token->getContent()] = $index;
                continue;
            }

            if ($type !== 'method') {
                continue;
            }

            $methodIndex = $this->tokens->getNextMeaningfulToken($index);
            $methodToken = $this->tokens[$methodIndex];
            if (strtolower($methodToken->getContent()) !== '__construct') {
                continue;
            }

            $extractedClasses[$classIndex]['constructor'] = $methodIndex;
            $extractedClasses[$classIndex]['constructorArguments'] = $this->extractFunctionArguments($methodIndex);
        }

        return $extractedClasses;
    }

    private function extractFunctionArguments(int $index): array
    {
        return array_map(static function ($argument) {
            return $argument->getNameIndex();
        }, (new FunctionsAnalyzer())->getFunctionArguments($this->tokens, $index));
    }

    protected function extractVariableAssignmentSequence(string $variableName, int $from, int $to): ?array
    {
        $variableNameWithoutSigil = str_replace("$", "", $variableName);

        return $this->tokens->findSequence(
            [
                [T_VARIABLE, '$this'],
                [T_OBJECT_OPERATOR],
                [T_STRING, $variableNameWithoutSigil],
                '=',
                [T_VARIABLE, $variableName],
                ';'
            ],
            $from,
            $to
        );
    }

    private function createPropertyPromotionInsertTokens(int $visibilityIndex, int $propertyIndex): array
    {
        $propertyHasAttribution = $this->tokens->getNextMeaningfulToken($propertyIndex);
        if ($this->tokens[$propertyHasAttribution]->getContent() === "=") {
            $propertyIndex = $this->tokens->getNextMeaningfulToken($propertyHasAttribution);
        }

        return array_map(function ($index) {
            try {
                return $this->convertVisibilityToken($this->tokens[$index]);
            } catch (InvalidArgumentException $e) {
                return $this->tokens[$index];
            }
        }, range($visibilityIndex, $propertyIndex));
    }

    private function convertVisibilityToken(Token $token): Token
    {
        if ($token->getId()) {
            $convertedId = $this->convertPropertyVisibilityIdToPromotedPropertyId($token->getId());
        }

        return new Token([$convertedId ?? "", $token->getContent()]);
    }

    private function convertPropertyVisibilityIdToPromotedPropertyId(int $id): int
    {
        switch ($id) {
            case T_PUBLIC:
                return CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC;
            case T_PROTECTED:
                return CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED;
            case T_PRIVATE:
                return CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Unsupported it "%d" given, supported ids are: "%s"',
                $id,
                implode(', ', [T_PUBLIC, T_PROTECTED, T_PRIVATE])
            )
        );
    }

    private function findPropertyVisibilityIndex(int $propertyIndex): int
    {
        for ($i = $propertyIndex - 1; $i >= $propertyIndex - 6; $i--) {
            if ($this->tokens[$i]->isGivenKind([T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
                return $i;
            }
        }

        throw new RuntimeException(sprintf(
            'No visibility (public, protected, private) found for property "%s"',
            $this->tokens[$propertyIndex]->getContent()
        ));
    }

    private function clearVariableAssignment(int $from, int $to): void
    {
        if ($this->tokens[$from - 1]->isWhitespace()) {
            $from --;
        }

        $this->tokens->clearRange($from, $to);
    }

    private function clearClassProperty(int $from, int $to): void
    {
        $docToken = $this->tokens->getPrevNonWhitespace($from);
        if ($docToken && $this->tokens[$docToken]->isGivenKind(T_DOC_COMMENT)) {
            $from = $docToken;
        }
        if ($this->tokens[$from - 1]->isWhitespace()) {
            $from--;
        }

        $this->tokens->clearRange($from, $to);
    }
}
