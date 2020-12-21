<?php
/**
 * Data Mapper
 *
 * @link      https://github.com/hiqdev/php-data-mapper
 * @package   php-data-mapper
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2017-2020, HiQDev (http://hiqdev.com/)
 */

namespace hiqdev\DataMapper\Query\Builder;

use hiqdev\DataMapper\Attribute\AttributeInterface;
use hiqdev\DataMapper\Query\Field\AttributedFieldInterface;
use hiqdev\DataMapper\Query\Field\FieldConditionBuilderInterface;
use hiqdev\DataMapper\Query\Field\FieldInterface;
use hiqdev\DataMapper\Query\Field\SQLFieldInterface;
use hiqdev\DataMapper\Validator\AttributeValidationException;
use hiqdev\DataMapper\Validator\AttributeValidator;
use hiqdev\DataMapper\Validator\AttributeValidatorFactoryInterface;

final class QueryConditionBuilder implements QueryConditionBuilderInterface
{
    private AttributeValidatorFactoryInterface $attributeValidatorFactory;
    private QueryConditionBuilderFactoryInterface $conditionBuilderFactory;

    private array $builderMap;

    public function __construct(array $builderMap, QueryConditionBuilderFactoryInterface $conditionBuilderFactory, AttributeValidatorFactoryInterface $attributeValidatorFactory)
    {
        $this->attributeValidatorFactory = $attributeValidatorFactory;
        $this->conditionBuilderFactory = $conditionBuilderFactory;
        $this->builderMap = $builderMap;
    }

    /** {@inheritdoc} */
    public function build(FieldInterface $field, string $key, $value)
    {
        if (isset($this->builderMap[get_class($field)])) {
            $builderClassName = $this->builderMap[get_class($field)];
            $builder = $this->conditionBuilderFactory->build($builderClassName);

            return $builder->build($field, $key, $value);
        }

        [$operator, $attribute] = $this->parseFieldFilterKey($field, $key);
        if ($field instanceof FieldConditionBuilderInterface) {
            return $field->buildCondition($operator, $attribute, $value);
        }

        if ($field instanceof SQLFieldInterface) {
            if (is_iterable($value)) {
                return [$field->getSql() => $this->ensureConditionValueIsValid($field, 'in', $value)];
            }

            $operatorMap = [
                'eq' => '=',
                'ne' => '!=',
            ];

            return [
                $operatorMap[$operator] ?? $operator,
                $field->getSql(),
                $this->ensureConditionValueIsValid($field, $operator, $value),
            ];
        }

        throw new \BadMethodCallException(sprintf('The passed field %s can not be built', $field->getName()));
    }

    public function canApply(FieldInterface $field, string $key, $value): bool
    {
        if (isset($this->builderMap[get_class($field)])) {
            $builderClassName = $this->builderMap[get_class($field)];
            $builder = $this->conditionBuilderFactory->build($builderClassName);

            $canApply = $builder->canApply($field, $key, $value);
            if ($canApply !== null) {
                return $canApply;
            }
        }

        [, $attribute] = $this->parseFieldFilterKey($field, $key);

        return $attribute === $field->getName();
    }

    /**
     * @param mixed $value
     * @throws AttributeValidationException
     * @return mixed normalized $value
     */
    private function ensureConditionValueIsValid(FieldInterface $field, string $operator, $value)
    {
        if (!$field instanceof AttributedFieldInterface) {
            return $value;
        }

        $validator = $this->getAttributeOperatorValidator($field->getAttribute(), $operator);
        $value = $validator->normalize($value);
        $validator->ensureIsValid($value);

        return $value;
    }

    /**
     * @param string $key the search key for operator and attribute name extraction
     * @return array an array of two items: the comparison operator and the attribute name
     * @psalm-return array{0: string, 1: string} an array of two items: the comparison operator and the attribute name
     */
    private function parseFieldFilterKey(FieldInterface $field, string $key)
    {
        if (!$field instanceof AttributedFieldInterface
            || $field->getName() === $key
        ) {
            return ['eq', $key];
        }

        /*
         * Extracts underscore suffix from the key.
         *
         * Examples:
         * client_id -> 0 - client_id, 1 - client, 2 - _id, 3 - id
         * server_owner_like -> 0 - server_owner_like, 1 - server_owner, 2 - _like, 3 - like
         */
        preg_match('/^(.*?)(_((?:.(?!_))+))?$/', $key, $matches);

        $operator = 'eq';

        // If the suffix is in the list of acceptable suffix filer conditions
        if (isset($matches[3]) && in_array($matches[3], $field->getAttribute()->getSupportedOperators(), true)) {
            $operator = $matches[3];
            $key = $matches[1];
        }

        return [$operator, $key];
    }

    private function getAttributeOperatorValidator(AttributeInterface $attribute, string $operator): AttributeValidator
    {
        $rule = $attribute->getRuleForOperator($operator);

        return $this->attributeValidatorFactory->createByDefinition($rule);
    }
}
