<?php
/**
 * PhpFilterer
 *
 * @package php-logical-filter
 * @author  Jean Claveau
 */
namespace JClaveau\LogicalFilter\Filterer;
use       JClaveau\LogicalFilter\LogicalFilter;
use       JClaveau\LogicalFilter\Rule\EqualRule;
use       JClaveau\LogicalFilter\Rule\BelowRule;
use       JClaveau\LogicalFilter\Rule\AboveRule;
use       JClaveau\LogicalFilter\Rule\NotEqualRule;
use       JClaveau\LogicalFilter\Rule\InRule;
use       JClaveau\LogicalFilter\Rule\NotInRule;

/**
 */
class PhpFilterer extends Filterer
{
    /**
     */
    public function validateRule ($field, $operator, $value, $row, array $path, $all_operands, $options)
    {
        if ($field === \JClaveau\LogicalFilter\value) {
            $value_to_validate = $row;
        }
        elseif ($field === \JClaveau\LogicalFilter\key) {
            $value_to_validate = array_pop($path);
        }
        elseif (!isset($row[$field])) {
            $value_to_validate = null;
        }
        else {
            $value_to_validate = $row[ $field ];
        }

        if ($operator === EqualRule::operator) {
            if (!isset($value_to_validate)) {
                 // ['field', '=', null] <=> isset($row['field'])
                 // [row, '=', null] <=> $row !== null
                $result = $value === null;
            }
            else {
                // TODO support strict comparisons
                $result = $value_to_validate == $value;
            }
        }
        elseif ($operator === InRule::operator) {
            if (!isset($value_to_validate)) {
                $result = false;
            }
            else {
                $result = in_array($value_to_validate, $value);
            }
        }
        elseif ($operator === BelowRule::operator) {
            if (!isset($value_to_validate)) {
                $result = false;
            }
            else {
                $result = $value_to_validate < $value;
            }
        }
        elseif ($operator === AboveRule::operator) {
            if (!isset($value_to_validate)) {
                $result = false;
            }
            else {
                $result = $value_to_validate > $value;
            }
        }
        elseif ($operator === NotEqualRule::operator) {
            if ($value === null) {
                $result = isset($value_to_validate);
            }
            else {
                $result = $value_to_validate != $value;
            }
        }
        elseif ($operator === NotInRule::operator) {
            if (!isset($value_to_validate)) {
                $result = true;
            }
            else {
                $result = !in_array($value_to_validate, $value);
            }
        }
        else {
            throw new \InvalidArgumentException(
                "Unhandled operator: " . $operator
            );
        }

        // var_dump(
            // "$field, $operator, " . var_export($value, true)
             // . ' vs ' . var_export($value_to_validate, true) . ' => ' . var_export($result, true)
             // . "\n\n"
             // . var_export($row, true)
        // );
        // exit;
        return $result;
    }

    /**/
}
