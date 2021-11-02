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
    const VISIBILITY_KINDS = [T_PUBLIC, T_PROTECTED, T_PRIVATE];
    /**
     * @var Tokens
     */
    private $tokens;

    private const TYPE_KINDS = [T_STRING, CT::T_ARRAY_TYPEHINT, T_NS_SEPARATOR, CT::T_NULLABLE_TYPE];

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
            if (!isset($class['constructor'])) {
                continue;
            }

            $constructorStart = $this->tokens->getNextTokenOfKind($class['constructor'], ['{']);
            $constructorEnd = $this->tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $constructorStart);

            foreach (array_reverse($class['constructorArguments']) as $name => $index) {
                if (!isset($class['properties'][$name])) {
                    continue;
                }

                if ($this->isVariadicParameter($index)) {
                    continue;
                }

                $variableAssignmentSequence = $this->extractVariableAssignmentSequence($name, $constructorStart, $constructorEnd);
                if ($variableAssignmentSequence === null) {
                    continue;
                }

                $propertyIndex = $class['properties'][$name];

                if (!$this->isTypeEqualToType($index, $propertyIndex)) {
                    continue;
                }

                $propertyNextMeaningfulTokenIndex = $this->tokens->getNextTokenOfKind($propertyIndex, [T_STRING, ";"]);

                try {
                    $propertyVisibilityIndex = $this->findPropertyVisibilityIndex($propertyIndex);
                } catch (RuntimeException $e) {
                    continue;
                }

                $this->clearRangeAndPrecedingWhitespace(
                    array_key_first($variableAssignmentSequence),
                    array_key_last($variableAssignmentSequence)
                );

                $propertyVisibilityToken = $this->tokens[$propertyVisibilityIndex];

                $this->clearClassProperty($propertyVisibilityIndex, $propertyNextMeaningfulTokenIndex);
                $this->insertPropertyVisibility($index, $propertyVisibilityToken);
            }
        }

        $this->tokens->clearEmptyTokens();
    }

    public function isCandidate(Tokens $tokens): bool
    {
        if (!$tokens->isAnyTokenKindsFound([T_CLASS, T_TRAIT])) {
            return false;
        }

        return \PHP_VERSION_ID >= 80000 && $tokens->isAllTokenKindsFound([T_FUNCTION, T_VARIABLE]);
    }

    private function extractClassData(): array
    {
        $tokenAnalyzer = new TokensAnalyzer($this->tokens);

        $classyElements = $tokenAnalyzer->getClassyElements();
        $abstractClasses = $this->getAbstractClasses();

        $extractedClasses = [];
        foreach ($classyElements as $index => $classyElement) {
            $token = $classyElement['token'];
            $type = $classyElement['type'];
            $classIndex = $classyElement['classIndex'];

            if (isset($abstractClasses[$classIndex])) {
                continue;
            }

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

    private function convertVisibilityToken(Token $token): Token
    {
        if ($token->getId() === null) {
            throw new InvalidArgumentException('Token without id cannot be converted');
        }

        $convertedId = $this->convertPropertyVisibilityIdToPromotedPropertyId($token->getId());

        return new Token([$convertedId, $token->getContent()]);
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
                implode(', ', self::VISIBILITY_KINDS)
            )
        );
    }

    private function findPropertyVisibilityIndex(int $propertyIndex): int
    {
        $index = $propertyIndex;


        while ($index = $this->tokens->getPrevMeaningfulToken($index)) {
            if ($this->tokens[$index]->getContent() === ';') {
                break;
            }

            if ($this->tokens[$index]->isGivenKind(self::VISIBILITY_KINDS)) {
                return $index;
            }
        }

        throw new RuntimeException(sprintf(
            'No visibility (public, protected, private) found for property "%s"',
            $this->tokens[$propertyIndex]->getContent()
        ));
    }

    private function clearRangeAndPrecedingWhitespace(int $indexStart, int $indexEnd): void
    {
        if ($this->tokens[$indexStart - 1]->isWhitespace()) {
            $indexStart --;
        }

        $this->tokens->clearRange($indexStart, $indexEnd);
    }

    private function clearClassProperty(int $indexStart, int $indexEnd): void
    {
        $docToken = $this->tokens->getPrevNonWhitespace($indexStart);
        if ($docToken && $this->tokens[$docToken]->isGivenKind(T_DOC_COMMENT)) {
            $indexStart = $docToken;
        }

        $this->clearRangeAndPrecedingWhitespace($indexStart, $indexEnd);
    }

    private function isVariadicParameter(int $index): bool
    {
        $prevMeaningfulToken = $this->tokens->getPrevMeaningfulToken($index);
        return $this->tokens[$prevMeaningfulToken]->getContent() === '...';
    }

    private function insertPropertyVisibility(int $index, Token $token): void
    {
        $insertAt = $index;
        $convertedToken = $this->convertVisibilityToken($token);

        while ($index = $this->tokens->getPrevMeaningfulToken($index)) {
            if (!$this->tokens[$index]->isGivenKind(self::TYPE_KINDS)) {
                break;
            }

            $insertAt = $index;
        }

        $this->tokens->insertAt($insertAt, [$convertedToken, new Token([T_WHITESPACE, " "])]);
    }

    private function getAbstractClasses(): array
    {
        return array_filter(
            $this->tokens->findGivenKind(T_CLASS),
            function (int $index) {
                $prevMeaningfulToken = $this->tokens->getPrevMeaningfulToken($index);

                return $this->tokens[$prevMeaningfulToken]->isGivenKind([T_ABSTRACT]);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    private function isTypeEqualToType(int $indexA, int $indexB)
    {
        $typeTokensA = $this->getTypeTokens($indexA);
        $typeTokensB = $this->getTypeTokens($indexB);

        if (count($typeTokensA) !== count($typeTokensB)) {
            return false;
        }

        if (empty($typeTokensA)) {
            return true;
        }

        do {
            $typeTokenA = current($typeTokensA);
            $typeTokenB = current($typeTokensB);

            if (!$typeTokenA->equals($typeTokenB)) {
                return false;
            }
        } while (next($typeTokensA) && next($typeTokensB));

        return true;
    }

    /**
     * @return Token[]
     */
    private function getTypeTokens(int $index): array
    {
        $tokens = [];

        while ($index = $this->tokens->getPrevMeaningfulToken($index)) {
            if (!$this->tokens[$index]->isGivenKind(self::TYPE_KINDS)) {
                break;
            }

            $tokens[$index] = $this->tokens[$index];
        }

        return $tokens;
    }
}
