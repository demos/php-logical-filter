<?php
namespace JClaveau\LogicalFilter;

use JClaveau\LogicalFilter\Rule\AbstractOperationRule;
use JClaveau\LogicalFilter\Rule\AndRule;
use JClaveau\LogicalFilter\Rule\OrRule;
use JClaveau\LogicalFilter\Rule\NotRule;

/**
 */
class LogicalFilter implements \JsonSerializable
{
    /** @var  AndRule $rules */
    protected $rules;

    /** @var  array $ruleAliases */
    protected static $ruleAliases = [
        '!=' => 'not equal',
        '='  => 'equal',
        '>'  => 'above',
        '>=' => 'above or equal',
        '<'  => 'below',
        '<=' => 'below or equal',
    ];

    /**
     */
    public function __construct()
    {
        $this->rules = new AndRule;
    }

    /**
     * @param  string rule name
     *
     * @return string corresponding rule class name
     */
    public static function getRuleClass($rule_type)
    {
        $rule_class = __NAMESPACE__
            . '\\Rule\\'
            . str_replace('_', '', ucwords($rule_type, '_'))
            . 'Rule';

        if (!class_exists( $rule_class)) {
            throw new \InvalidArgumentException(
                "No rule class corresponding to the expected type: '$rule_type'. "
                ."Looking for '$rule_class'"
            );
        }

        return $rule_class;
    }

    /**
     * Add a constraint for the given field (a list a allowed values).
     * We create an And rule that will gather all the possible combinations
     * of rules that will be applied to the given field.
     *
     * @param string $field
     * @param string $type
     * @param mixed  $value
     *
     * @return $this
     */
    public function addSimpleRule($field, $type, $values)
    {
        $this->rules->addOperand( self::generateSimpleRule(
            $field, $type, $values
        ) );

        return $this;
    }

    /**
     * Add a rule to the filter at its root.
     *
     * @param  AbstractRule  $rule
     * @return LogicalFilter $this
     */
    public function addRule( AbstractRule $rule )
    {
        $this->rules->addOperand( $rule );
        return $this;
    }

    /**
     *
     * @param string $field
     * @param string $type
     * @param mixed  $value
     *
     * @return $this
     */
    public static function generateSimpleRule($field, $type, $values)
    {
        $ruleClass = self::getRuleClass($type);

        return new $ruleClass( $field, $values );
    }

    /**
     * Transforms an array gathering different rules representing
     * atomic and operation rules into a tree of Rules added to the
     * current Filter.
     *
     * @param array $rules_composition
     *
     * @return $this
     */
    public function addCompositeRule(array $rules_composition)
    {
        $this->addCompositeRule_recursion(
            $rules_composition,
            $this->rules
        );

        return $this;
    }

    /**
     * Recursion auxiliary of addCompositeRule.
     *
     * @param array $rules_composition
     *
     * @return $this
     */
    private function addCompositeRule_recursion(
        array $rules_composition,
        AbstractOperationRule $recursion_position
    ) {
        if (!array_filter($rules_composition, function ($rule_composition_part) {
            return is_string($rule_composition_part);
        })) {
            // at least one operator is required for operation rules
            throw new \InvalidArgumentException(
                "Please provide an operator for the operation: \n"
                .var_export($rules_composition, true)
            );
        }
        elseif (    count($rules_composition) == 3
            &&  !in_array('and', $rules_composition)
            &&  !in_array('or',  $rules_composition)
            &&  !in_array('not', $rules_composition)
        ) {
            // atomic or composit rules
            $operand_left  = $rules_composition[0];
            $operation     = $rules_composition[1];
            $operand_right = $rules_composition[2];

            $rule = self::generateSimpleRule(
                $operand_left, $operation, $operand_right
            );
            $recursion_position->addOperand( $rule );
        }
        else {
            // operations
            if ($rules_composition[0] == 'not') {
                $rule = new NotRule();
                $operator = 'not';
            }
            elseif (in_array('and', $rules_composition)) {
                $rule = new AndRule();
                $operator = 'and';
            }
            elseif (in_array('or', $rules_composition)) {
                $rule = new OrRule();
                $operator = 'or';
            }
            else {
                throw new \Exception("Unhandled operation");
            }

            $operands_descriptions = array_filter(
                $rules_composition,
                function ($operand) use ($operator) {
                    return $operand !== $operator;
                }
            );

            $remaining_operations = array_filter(
                $operands_descriptions,
                function($operand) {
                    return !is_array($operand);
                }
            );

            if ($remaining_operations) {
                throw new \InvalidArgumentException(
                    "Mixing different operations in the same rule level not implemented: \n"
                    . implode(', ', $remaining_operations)."\n"
                    . 'in ' . var_export($rules_composition, true)
                );
            }

            $recursion_position->addOperand( $rule );

            if ($operator == 'not' && count($operands_descriptions) != 1) {
                throw new \InvalidArgumentException(
                    "Negations can have only one operand: \n"
                    .var_export($rules_composition, true)
                );
            }

            foreach ($operands_descriptions as $operands_description) {
                $this->addCompositeRule_recursion(
                    $operands_description,
                    $rule
                );
            }
        }

        return $this;
    }

    /**
     * Retrieve all the rules.
     *
     * @param  bool $copy By default copy the rule tree to avoid side effects.
     *
     * @return AbstractRule The tree of rules
     */
    public function getRules($copy = true)
    {
        return $copy ? $this->rules->copy() : $this->rules;
    }

    /**
     * Includes all the contraints of an other Filter into the current one.
     * /
    public function combineWith( LogicalFilter $filterToMerge )
    {
        foreach ($filterToMerge->getRules() as $field => $rules) {
            foreach ($rules as $rule)
                $this->rules[$field][] = $rule;
        }

        return $this;
    }

    /**
     * Remove any constraint being a duplicate of another one.
     *
     * @return $this
     */
    public function simplify()
    {
        $this->rules->simplify();
        return $this;
    }

    /**
     * Checks if there is at least on set of conditions which is not
     * contradictory.
     *
     * @return bool
     */
    public function hasSolution()
    {
        return $this->rules->hasSolution();
    }

    /**
     * Returns an array describing the rule tree of the Filter.
     */
    public function toArray()
    {
        return $this->rules->toArray();
    }

    /**
     * For implementing JsonSerializable interface.
     *
     * @see https://secure.php.net/manual/en/jsonserializable.jsonserialize.php
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Replaces every negation operation rules by its opposit not negated
     * one.
     *
     * This method scans the rule tree recursivelly.
     *
     * @return $this
     */
    public function removeNegations()
    {
        $this->rules->removeNegations();
        return $this;
    }

    /**
     * Remove all OR rules so only one remain at the top of rules tree.
     *
     * This method scans the rule tree recursivelly.
     *
     * @return $this
     */
    public function upLiftDisjunctions()
    {
        // We always keep an AndRule as root to be able to add new rules
        // to the Filter afterwards
        $this->rules = new AndRule([$this->rules->upLiftDisjunctions()]);
        return $this;
    }


    /**
     * Generates a string id corresponding to the constraints caracterising
     * the filter. This is mainly proposed to simplify cache key generation.
     *
     * @return string The key.
     * /
    public function getUid()
    {
        ksort($this->constraints);
        return hash('crc32b', var_export($this->constraints, true));
    }

    /**
     * Removes all the defined constraints.
     *
     * @return $this
     */
    public function flushRules()
    {
        $this->rules = [];
        return $this;
    }

    /**
     * Extracts the keys from the filter and checks that none is unused.
     *
     * @return new Filter instance
     * /
    public function useAllRules(array $rules_to_use)
    {
        $rules = $this->copyRules();

        $parameters = [];

        foreach ($rules_to_use as $parameter_name => $rule_to_use) {

            // TODO simplify $rule_to_use to have only one set of parameters
            //


            if (isset( $rules[ $rule_to_use[0] ][ $rule_to_use[1] ] )) {

                $parameters[ $parameter_name ]
                    = $rules[ $rule_to_use[0] ][ $rule_to_use[1] ]->getParameters();

                unset($rules[ $rule_to_use[0] ][ $rule_to_use[1] ]);
            }
        }

        if (array_filter($rules)) {
            throw new \ErrorException("Unused rules in the filter: "
                .print_r($rules, true));
        }

        return $parameters;
    }

    /**
     * Clone the current object and its rules.
     *
     * @return LogicalFilter A copy of the current instance with a copied ruletree
     */
    public function copy()
    {
        $newFilter = clone $this;
        return $newFilter
            ->flushRules()
            ->addRules( $this->rules->copy() );
    }

    /**/
}