<?php
namespace JClaveau\LogicalFilter\Rule;
use       JClaveau\VisibilityViolator\VisibilityViolator;

/**
 * Logical conjunction:
 * @see https://en.wikipedia.org/wiki/Logical_conjunction
 */
class AndRule extends AbstractOperationRule
{
    /** @var string operator */
    const operator = 'and';

    /**
     * Replace all the OrRules of the RuleTree by one OrRule at its root.
     *
     * @todo rename as RootifyDisjunjctions?
     * @todo return $this (implements a Rule monad?)
     *
     * @return OrRule copied operands with one OR at its root
     */
    public function rootifyDisjunctions(array $simplification_options)
    {
        if (!$this->isNormalizationAllowed($simplification_options))
            return $this;

        $this->moveSimplificationStepForward( self::rootify_disjunctions, $simplification_options );

        $upLiftedOperands = [];
        foreach ($this->getOperands() as $operand) {
            $operand = $operand->copy();
            if ($operand instanceof AbstractOperationRule)
                $operand = $operand->rootifyDisjunctions($simplification_options);

            $upLiftedOperands[] = $operand;
        }

        // If the AndRule doesn't contain any OrRule , there is nothing to uplift
        if (!array_filter($upLiftedOperands, function($operand) {
            return $operand instanceof OrRule;
        })) {
            return new AndRule($upLiftedOperands);
        }

        $firstAndOperand = new AndRule();

        // This OrRule should contain only AndRules during its generation
        $upLiftedOr = new OrRule([
            $firstAndOperand
        ]);

        // var_dump($upLiftedOperands);
        // $this->dump(true);

        foreach ($upLiftedOperands as $i => $operand) {

            if ($operand instanceof NotRule) {
                if (    ($operand instanceof NotEqualRule || $operand instanceof NotInRule)
                    && ! $operand->isNormalizationAllowed($simplification_options)
                ) {
                    foreach ($upLiftedOr->getOperands() as $upLifdtedOperand) {
                        $upLifdtedOperand->addOperand( $operand->copy() );
                    }
                }
                else {
                    throw new \LogicException(
                        "Rootifying disjunctions MUST be done after negations removal instead of '".$operand."' \n"
                        .$operand
                    );
                }
            }
            elseif ($operand instanceof OrRule && $operand->isNormalizationAllowed($simplification_options)) {

                // If an operand is an Or, me transform the current
                // (A' || A") && (B')       <=> (A' && B') || (A" && B');
                // (A' || A") && (B' || B") <=> (A' && B') || (A' && B") || (A" && B') || (A" && B");
                // (A' || A") && (B' || B") && (C' || C") <=>
                //    (A' && B' && C') || (A' && B' && C") || (A' && B" && C') || (A' && B" && C")
                // || (A" && B' && C') || (A" && B' && C") || (A" && B" && C') || (A" && B" && C");
                $newUpLiftedOr = new OrRule;
                foreach ($operand->getOperands() as $subOperand) {
                    foreach ($upLiftedOr->getOperands() as $upLiftedOrSubOperand) {
                        $newUpLiftedOrSubOperand = $upLiftedOrSubOperand->copy();
                        $newUpLiftedOrSubOperand->addOperand( $subOperand->copy() );
                        if ($newUpLiftedOrSubOperand->simplify($simplification_options)->hasSolution($simplification_options))
                            $newUpLiftedOr->addOperand( $newUpLiftedOrSubOperand );
                    }
                }

                $upLiftedOr = $newUpLiftedOr;
            }
            else {
                // append the operand to all the operands of the $upLiftedOr
                foreach ($upLiftedOr->getOperands() as $upLifdtedOperand) {
                    if (!$upLifdtedOperand instanceof AndRule) {
                        throw new \LogicException(
                             "Operands of the uplifted OrRule MUST be AndRules during"
                            ."the combination."
                        );
                    }

                    $upLifdtedOperand->addOperand( $operand->copy() );
                }
            }
        }

        return $upLiftedOr;
    }

    /**
     * @param array $options   + show_instance=false Display the operator of the rule or its instance id
     *
     * @return array
     *
     * @todo same as OrRule
     */
    public function toArray(array $options=[])
    {
        $default_options = [
            'show_instance' => false,
            'sort_operands' => false,
            'semantic'      => false,
        ];
        foreach ($default_options as $default_option => &$default_value) {
            if (!isset($options[ $default_option ]))
                $options[ $default_option ] = $default_value;
        }

        if (!$options['show_instance'] && !empty($this->cache['array']))
            return $this->cache['array'];

        $operands_as_array = [
            $options['show_instance'] ? $this->getInstanceId() : self::operator,
        ];

        $operands = $this->operands;
        if ($options['semantic']) {
            // Semantic array: ['operator', 'semantic_id_of_operand1', 'semantic_id_of_operand2', ...]
            // with sorted semantic ids
            $operands_semantic_ids = array_keys($operands);
            sort($operands_semantic_ids);
            return array_merge(
                [self::operator],
                $operands_semantic_ids
            );
        }
        else {
            foreach ($operands as $operand)
                $operands_as_array[] = $operand->toArray($options);

            if (!$options['show_instance'])
                return $this->cache['array'] = $operands_as_array;
            else
                return $operands_as_array;
        }
    }

    /**
     */
    public function toString(array $options=[])
    {
        $operator = self::operator;
        if (!$this->operands) {
            return $this->cache['string'] = "['{$operator}']";
        }

        $indent_unit = isset($options['indent_unit']) ? $options['indent_unit'] : '';
        $line_break  = $indent_unit ? "\n" : '';

        $out = "['{$operator}',$line_break";

        foreach ($this->operands as $operand) {
            $out .= implode("\n", array_map(function($line) use (&$indent_unit) {
                return $indent_unit.$line;
            }, explode("\n", $operand->toString($options)) )) . ",$line_break";
        }

        $out .= ']';

        return $this->cache['string'] = $out;
    }

    /**
     * Remove AndRules operands of AndRules
     */
    public function removeSameOperationOperands()
    {
        foreach ($this->operands as $i => $operand) {
            if ( ! is_a($operand, AndRule::class))
                continue;

            if ( ! $operands = $operand->getOperands())
                continue;

            // Id AND is an operand on AND they can be merge (and the same with OR)
            foreach ($operands as $sub_operand) {
                $this->addOperand( $sub_operand->copy() );
            }
            unset($this->operands[$i]);

            // possibility of mono-operand or dupicates
            $has_been_changed = true;
        }

        return !empty($has_been_changed);
    }

    /**
     * Removes rule branches that cannot produce result like:
     * A = 1 || (B < 2 && B > 3) <=> A = 1
     *
     * @return AndRule $this
     */
    public function removeInvalidBranches(array $simplification_options)
    {
        if (!$this->isNormalizationAllowed($simplification_options))
            return $this;

        $this->moveSimplificationStepForward(self::remove_invalid_branches, $simplification_options);

        foreach ($this->operands as $i => $operand) {
            if ($operand instanceof AndRule || $operand instanceof OrRule ) {
                $this->operands[$i] = $operand->removeInvalidBranches($simplification_options);
                if (!$this->operands[$i]->hasSolution()) {
                    $this->operands = [];
                    return $this;
                }
            }
        }

        $operandsByFields = $this->groupOperandsByFieldAndOperator();

        // $this->dump(true);

        foreach ($operandsByFields as $field => $operandsByOperator) {

            if (!empty($operandsByOperator[ EqualRule::operator ])) {

                foreach ($operandsByOperator[ EqualRule::operator ] as $equalRule) {
                    // Multiple equal rules without the same value is invalid
                    if (isset($previousEqualRule) && $previousEqualRule->getValue() != $equalRule->getValue()) {
                        $this->operands = [];
                        return $this;
                    }
                    $previousEqualRule = $equalRule;
                }
                unset($previousEqualRule);

                $equalRule = reset($operandsByOperator[ EqualRule::operator ]);

                if (   !empty($operandsByOperator[ BelowRule::operator ])
                    && $equalRule->getValue() === null
                ) {
                    $this->operands = [];
                    return $this;
                }

                if (   !empty($operandsByOperator[ BelowRule::operator ])
                    && $equalRule->getValue() >= reset($operandsByOperator[ BelowRule::operator ])->getMaximum()
                ) {
                    $this->operands = [];
                    return $this;
                }

                if (   !empty($operandsByOperator[ AboveRule::operator ])
                    && $equalRule->getValue() === null
                ) {
                    $this->operands = [];
                    return $this;
                }

                if (   !empty($operandsByOperator[ AboveRule::operator ])
                    && $equalRule->getValue() <= reset($operandsByOperator[ AboveRule::operator ])->getMinimum()
                ) {
                    $this->operands = [];
                    return $this;
                }

                if (   !empty($operandsByOperator[ NotEqualRule::operator ])
                    && $equalRule->getValue() == reset($operandsByOperator[ NotEqualRule::operator ])->getValue()
                ) {
                    $this->operands = [];
                    return $this;
                }

                if (   !empty($operandsByOperator[ NotEqualRule::operator ])
                    && $equalRule->getValue() === null
                    && reset($operandsByOperator[ NotEqualRule::operator ])->getValue() === null
                ) {
                    $this->operands = [];
                    return $this;
                }
            }
            elseif (   !empty($operandsByOperator[ BelowRule::operator ])
                    && !empty($operandsByOperator[ AboveRule::operator ])) {
                $aboveRule = reset($operandsByOperator[ AboveRule::operator ]);
                $belowRule = reset($operandsByOperator[ BelowRule::operator ]);

                if ($belowRule->getMaximum() <= $aboveRule->getMinimum()) {
                    $this->operands = [];
                    return $this;
                }
            }
        }

        return $this;
    }

    /**
     * Checks if a simplified AndRule has incompatible operands like:
     * + a = 3 && a > 4
     * + a = 3 && a < 2
     * + a > 3 && a < 2
     *
     * @return bool If the AndRule can have a solution or not
     */
    public function hasSolution(array $contextual_options=[])
    {
        if (!$this->simplicationStepReached(self::simplified)) {
            throw new \LogicException(
                "hasSolution has no sens if the rule is not simplified instead of being at: "
                .var_export($this->current_simplification_step, true)
            );
        }

        // atomic rules
        foreach ($this->getOperands() as $operand) {
            if (method_exists($operand, 'hasSolution') && !$operand->hasSolution())
                return false;
        }

        return !empty($this->getOperands());
    }


    /**
     * + if A > 2 && A > 1 <=> A > 2
     * + if A < 2 && A < 1 <=> A < 1
     */
    protected static function simplifySameOperands(array $operandsByFields)
    {
        // unifying same operands
        foreach ($operandsByFields as $field => $operandsByOperator) {

            foreach ($operandsByOperator as $operator => $operands) {
                unset($previous_operand);

                try {
                    if ($operator == AboveRule::operator) {
                        usort($operands, function( AboveRule $a, AboveRule $b ) {
                            if ($a->getMinimum() === null)
                                return 1;

                            if ($b->getMinimum() === null)
                                return -1;

                            if ($a->getMinimum() > $b->getMinimum())
                                return -1;

                            return 1;
                        });
                        $operands = [reset($operands)];
                    }
                    elseif ($operator == BelowRule::operator) {
                        usort($operands, function( BelowRule $a, BelowRule $b ) {
                            if ($a->getMaximum() === null)
                                return 1;

                            if ($b->getMaximum() === null)
                                return -1;

                            if ($a->getMaximum() < $b->getMaximum())
                                return -1;

                            return 1;
                        });
                        $operands = [reset($operands)];
                    }
                    elseif ($operator == EqualRule::operator) {
                        // TODO add an option for the support strict comparison
                        foreach ($operands as $i => $operand) {
                            if (!isset($previous_operand)) {
                                $previous_operand = $operand;
                                continue;
                            }

                            if ($previous_operand == $operand) {
                                unset($operands[$i]);
                                continue;
                            }
                            else {
                                // Same field expected to be two differents
                                // values at the same time has no sens so
                                // we remove all the operands of the current
                                // AndRule (TODO FalseRule)
                                return [];
                            }
                        }
                    }
                    elseif ($operator == InRule::operator) {
                        $first_in = reset($operands);

                        foreach ($operands as $i => $next_in) {
                            if ($first_in === $next_in)
                                continue;

                            $first_in->setPossibilities( array_intersect(
                                $first_in->getPossibilities(),
                                $next_in->getPossibilities()
                            ) );

                            unset($operands[$i]);
                        }

                        // [field in []] <=> false
                        if (!$first_in->getPossibilities())
                            return [];
                    }
                    elseif ($operator == NotInRule::operator) {
                        $first_not_in = reset($operands);

                        foreach ($operands as $i => $next_not_in) {
                            if ($first_not_in === $next_not_in)
                                continue;

                            $first_not_in->setPossibilities( array_merge(
                                $first_not_in->getPossibilities(),
                                $next_not_in->getPossibilities()
                            ) );

                            unset($operands[$i]);
                        }
                    }
                }
                catch (\Exception $e) {
                    VisibilityViolator::setHiddenProperty($e, 'message', $e->getMessage() . "\n" . var_export([
                            'operands' => $operands,
                            // 'this'     => $this,
                        ], true)
                    );

                    // \Debug::dumpJson($this->toArray(), true);
                    throw $e;
                }

                $operandsByFields[ $field ][ $operator ] = $operands;
            }
        }

        return $operandsByFields;
    }

    /**
     * + if A = 2 && A > 1 <=> A = 2
     * + if A = 2 && A < 4 <=> A = 2
     */
    protected static function simplifyDifferentOperands(array $operandsByFields)
    {
        foreach ($operandsByFields as $field => &$operandsByOperator) {

            foreach ([
                    EqualRule::operator,
                    AboveRule::operator,
                    AboveRule::operator,
                    InRule::operator,
                    NotInRule::operator
                ]
                as $unifyable_operator
            ) {
                if (!empty($operandsByOperator[ $unifyable_operator ])) {
                    if (count($operandsByOperator[ $unifyable_operator ]) != 1) {
                        throw new \LogicException(
                            __METHOD__ . " MUST be called after unifyAtomicOperands() "
                            ."to have only one '$unifyable_operator' predicate istead of:\n"
                            ."[\n".implode( ",\n", array_map(function ($rule) {
                                    return $rule->toString();
                                }, $operandsByOperator[ $unifyable_operator ])
                            )."\n]"
                        );
                    }
                }
            }

            // EqualRule comparisons
            if (!empty($operandsByOperator[ EqualRule::operator ])) {
                if (count($operandsByOperator[ EqualRule::operator ]) != 1) {
                    // Multiple Equal rules for one field with different values has no sense
                    continue;
                }

                $equalRule = reset( $operandsByOperator[ EqualRule::operator ] );

                if (!empty($operandsByOperator[ NotEqualRule::operator ])) {
                    foreach ($operandsByOperator[ NotEqualRule::operator ] as $i => $not_equal_rule) {

                        if ($equalRule->getValue() !== null) {
                            if ($not_equal_rule->getValue() === null) // means if exists <=> equals something
                                unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                            elseif ($not_equal_rule->getValue() != $equalRule->getValue())
                                unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                        }
                        elseif ($equalRule->getValue() === null ) {
                            if ($not_equal_rule->getValue() !== null)
                                unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                            // else we let the "equal null" and the "not equal null" for the romeInvalidBranches step
                        }
                    }
                }

                if (!empty($operandsByOperator[ AboveRule::operator ])) {

                    $aboveRule = reset($operandsByOperator[ AboveRule::operator ]);
                    if ($equalRule->getValue() !== null && $aboveRule->getMinimum() < $equalRule->getValue())
                        unset($operandsByOperator[ AboveRule::operator ]);
                }

                if (!empty($operandsByOperator[ BelowRule::operator ])) {

                    $belowRule = reset($operandsByOperator[ BelowRule::operator ]);
                    if ($equalRule->getValue() !== null && $belowRule->getMaximum() > $equalRule->getValue())
                        unset($operandsByOperator[ BelowRule::operator ]);
                }

                if (!empty($operandsByOperator[ InRule::operator ])) {

                    $possibilities = reset($operandsByOperator[ InRule::operator ])->getPossibilities();

                    if (in_array($equalRule->getValue(), $possibilities)) {
                        unset($operandsByOperator[ InRule::operator ]);
                    }
                    else {
                        // We flush possibilities of the InRule
                        // TODO Replace it by a FalseRule
                        $operandsByOperator[ InRule::operator ][0]->setPossibilities([]);
                        // and also remove the equal rule to shorten the reste of the simplification process
                        unset($operandsByOperator[ EqualRule::operator ]);
                    }
                }

                if (!empty($operandsByOperator[ NotInRule::operator ])) {

                    $notInRule = reset($operandsByOperator[ NotInRule::operator ]);
                    if (in_array($equalRule->getValue(), $notInRule->getPossibilities())) {
                        // ['field', '=', 4] && ['field', '!in', [4]...] <=> false
                        return [];
                    }
                    else {
                        unset($operandsByOperator[ NotInRule::operator ]);
                    }
                    // $notInRule->dump(true);
                }
            }

            // NotEqualRule null comparisons
            if (!empty($operandsByOperator[ NotEqualRule::operator ])) {
                if (!empty($operandsByOperator[ NotEqualRule::operator ])) {
                    foreach ($operandsByOperator[ NotEqualRule::operator ] as $i => $notEqualRule) {

                        if ($notEqualRule->getValue() === null) {
                            if (!empty($operandsByOperator[ AboveRule::operator ])) {
                                unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                            }

                            if (!empty($operandsByOperator[ BelowRule::operator ])) {
                                unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                            }

                            if (!empty($operandsByOperator[ EqualRule::operator ])) {
                                if (reset($operandsByOperator[ EqualRule::operator ])->getValue() !== null)
                                    unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                            }
                        }
                        else {
                            if (!empty($operandsByOperator[ AboveRule::operator ])) {
                                if ($operandsByOperator[ AboveRule::operator ][0]->getMinimum() >= $notEqualRule->getValue())
                                    unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                            }

                            if (!empty($operandsByOperator[ BelowRule::operator ])) {
                                if ($operandsByOperator[ BelowRule::operator ][0]->getMaximum() <= $notEqualRule->getValue())
                                    unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                            }
                        }

                        if (!empty($operandsByOperator[ NotInRule::operator ])) {
                            $notInRule = reset($operandsByOperator[ NotInRule::operator ]);
                            if (!in_array($notEqualRule->getValue(), $notInRule->getPossibilities())) {
                                // TODO Replace it by a FalseRule
                                $operandsByFields[ $field ][ NotInRule::operator ][0]->setPossibilities(
                                    array_merge($notInRule->getPossibilities(), [$notEqualRule->getValue()])
                                );
                            }

                            unset($operandsByOperator[ NotEqualRule::operator ][$i]);
                        }

                        if (!empty($operandsByOperator[ InRule::operator ])) {
                            $inRule = reset($operandsByOperator[ InRule::operator ]);

                            $operandsByOperator[ InRule::operator ][0]->setPossibilities(
                                array_diff($inRule->getPossibilities(), [$notEqualRule->getValue()])
                            );
                        }
                    }
                }
            }

            // Comparison between InRules and NotInRules
            // This is an optimization to avoid NotIn explosion
            if (!empty($operandsByOperator[ InRule::operator ])) {
                $inRule = $operandsByOperator[ InRule::operator ][0];

                if (!empty($operandsByOperator[ NotInRule::operator ])) {
                    $notInRule = reset($operandsByOperator[ NotInRule::operator ]);
                    $operandsByOperator[ InRule::operator ][0]->setPossibilities(
                        array_diff( $inRule->getPossibilities(), $notInRule->getPossibilities())
                    );
                    unset($operandsByOperator[ NotInRule::operator ]);
                }

                if (!empty($operandsByOperator[ BelowRule::operator ])) {
                    $upper_limit = reset($operandsByOperator[ BelowRule::operator ])->getMaximum();

                    $operandsByOperator[ InRule::operator ][0]->setPossibilities(
                        array_filter( $inRule->getPossibilities(), function ($possibility) use ($upper_limit) {
                            return $possibility < $upper_limit;
                        } )
                    );

                    unset($operandsByOperator[ BelowRule::operator ]);
                }

                if (!empty($operandsByOperator[ AboveRule::operator ])) {
                    $lower_limit = reset($operandsByOperator[ AboveRule::operator ])->getMinimum();

                    $operandsByOperator[ InRule::operator ][0]->setPossibilities(
                        array_filter( $inRule->getPossibilities(), function ($possibility) use ($lower_limit) {
                            return $possibility > $lower_limit;
                        } )
                    );

                    unset($operandsByOperator[ AboveRule::operator ]);
                }

            }
        }

        return $operandsByFields;
    }

    /**
     * This method is meant to be used during simplification that would
     * need to change the class of the current instance by a normal one.
     *
     * @return AndRule The current instance (of or or subclass) or a new AndRule
     */
    public function setOperandsOrReplaceByOperation($new_operands)
    {
        try {
            return $this->setOperands( $new_operands );
        }
        catch (\LogicException $e) {
            return new AndRule( $new_operands );
        }
    }

    /**/
}
