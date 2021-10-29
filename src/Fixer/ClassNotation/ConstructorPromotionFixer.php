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
use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\FunctionsAnalyzer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use RuntimeException;

/**
 * TODO: Explain here
 *
 * @author Jeroen van den Heuvel <jeroenvdheuvel+github@hotmail.com>
 */
final class ConstructorPromotionFixer extends AbstractFixer
{
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
        // TODO: What about makign `$tokens` a class property instead of passing it along all the time?
        $classes = $this->extractClassData($tokens);

        foreach (array_reverse($classes) as $class) {
            $constructorStart = $tokens->getNextTokenOfKind($class['constructor'], ['{']);
            $constructorEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $constructorStart);

            foreach (array_reverse($class['constructorArguments']) as $name => $index) {
                if (!isset($class['properties'][$name])) {
                    continue;
                }

                $variableAssignmentSequence = $this->extractVariableAssignmentSequence($tokens, $name, $constructorStart, $constructorEnd);
                if ($variableAssignmentSequence === null) {
                    continue;
                }

                $propertyIndex = $class['properties'][$name];
                $propertyNextMeaningfulTokenIndex = $tokens->getNextTokenOfKind($propertyIndex, [T_STRING, ";"]);

                $propertyVisibilityIndex = $this->findPropertyVisibilityIndex($tokens, $propertyIndex);
                $insertTokens = $this->createPropertyPromotionInsertTokens($tokens, $propertyVisibilityIndex, $propertyIndex);

                $this->clearVariableAssignment(
                    $tokens,
                    array_key_first($variableAssignmentSequence),
                    array_key_last($variableAssignmentSequence)
                );

                $this->clearClassProperty($tokens, $propertyVisibilityIndex, $propertyNextMeaningfulTokenIndex);
                $tokens->clearAt($index[$name]);
                $tokens->insertAt($index[$name], $insertTokens);
            }
        }

        $tokens->clearEmptyTokens();
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAllTokenKindsFound([T_CLASS, T_FUNCTION, T_VARIABLE]);
    }

    private function extractClassData(Tokens $tokens): array
    {
        $tokenAnalyzer= new TokensAnalyzer($tokens);
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

            $methodIndex = $tokens->getNextMeaningfulToken($index);
            $methodToken = $tokens[$methodIndex];
            if (strtolower($methodToken->getContent()) !== '__construct') {
                continue;
            }

            $extractedClasses[$classIndex]['constructor'] = $methodIndex;
            $extractedClasses[$classIndex]['constructorArguments'] = $this->extractFunctionArguments($tokens, $methodIndex);
        }

        return $extractedClasses;
    }

    // TODO: Only enable on php 8

    private function extractFunctionArguments(Tokens $tokens, int $index): array
    {
        return array_map(static function ($argument) {
            return [$argument->getName() => $argument->getNameIndex()];
        }, (new FunctionsAnalyzer())->getFunctionArguments($tokens, $index));
    }

    protected function extractVariableAssignmentSequence(Tokens $tokens, string $variableName, int $from, int $to): ?array
    {
        $variableNameWithoutSigil = str_replace("$", "", $variableName);

        return $tokens->findSequence(
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

    private function createPropertyPromotionInsertTokens(Tokens $tokens, int $visibilityIndex, int $propertyIndex): array
    {
        $propertyHasAttribution = $tokens->getNextMeaningfulToken($propertyIndex);
        if ($tokens[$propertyHasAttribution]->getContent() === "=") {
            $propertyIndex = $tokens->getNextMeaningfulToken($propertyHasAttribution);
        }

        return array_map(function ($index) use ($tokens) {
            try {
                return $this->convertVisibilityToken($tokens[$index]);
            } catch (InvalidArgumentException $e) {
                return $tokens[$index];
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
            default:
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsupported it "%d" given, supported ids are: "%s"',
                        $id,
                        implode(', ', [T_PUBLIC, T_PROTECTED, T_PRIVATE])
                    )
                );
        }
    }

    private function findPropertyVisibilityIndex(Tokens $tokens, int $propertyIndex): int
    {
        for ($i = $propertyIndex - 1; $i >= $propertyIndex - 6; $i--) {
            if ($tokens[$i]->isGivenKind([T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
                return $i;
            }
        }

        throw new RuntimeException(sprintf(
            'No visibility (public, protected, private) found for property "%s"',
            $tokens[$propertyIndex]->getContent()
        ));
    }

    private function clearVariableAssignment(Tokens $tokens, int $from, int $to): void
    {
        if ($tokens[$from - 1]->isWhitespace()) {
            $from --;
        }

        $tokens->clearRange($from, $to);
    }

    private function clearClassProperty(Tokens $tokens, int $from, int $to): void
    {
        $docToken = $tokens->getPrevNonWhitespace($from);
        if ($docToken && $tokens[$docToken]->isGivenKind(T_DOC_COMMENT)) {
            $from = $docToken;
        }
        if ($tokens[$from - 1]->isWhitespace()) {
            $from--;
        }

        $tokens->clearRange($from, $to);
    }
}
