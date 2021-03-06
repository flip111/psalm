<?php
namespace Psalm\Internal\Analyzer\Statements\Expression\Fetch;

use PhpParser;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\NamespaceAnalyzer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\CallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\FileManipulation\FileManipulationBuffer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\DeprecatedProperty;
use Psalm\Issue\InvalidPropertyFetch;
use Psalm\Issue\InternalProperty;
use Psalm\Issue\MissingPropertyType;
use Psalm\Issue\MixedPropertyFetch;
use Psalm\Issue\NoInterfaceProperties;
use Psalm\Issue\NullPropertyFetch;
use Psalm\Issue\ParentNotFound;
use Psalm\Issue\PossiblyInvalidPropertyFetch;
use Psalm\Issue\PossiblyNullPropertyFetch;
use Psalm\Issue\UndefinedClass;
use Psalm\Issue\UndefinedDocblockClass;
use Psalm\Issue\UndefinedMagicPropertyFetch;
use Psalm\Issue\UndefinedPropertyFetch;
use Psalm\Issue\UndefinedThisPropertyFetch;
use Psalm\Issue\UninitializedProperty;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use function strtolower;
use function array_values;
use function in_array;
use function array_reverse;
use function array_keys;
use function count;
use function explode;
use Psalm\Internal\Taint\Source;

/**
 * @internal
 */
class PropertyFetchAnalyzer
{
    /**
     * @param   StatementsAnalyzer                   $statements_analyzer
     * @param   PhpParser\Node\Expr\PropertyFetch   $stmt
     * @param   Context                             $context
     *
     * @return  false|null
     */
    public static function analyzeInstance(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\PropertyFetch $stmt,
        Context $context
    ) {
        if (!$stmt->name instanceof PhpParser\Node\Identifier) {
            if (ExpressionAnalyzer::analyze($statements_analyzer, $stmt->name, $context) === false) {
                return false;
            }
        }

        if (ExpressionAnalyzer::analyze($statements_analyzer, $stmt->var, $context) === false) {
            return false;
        }

        if ($stmt->name instanceof PhpParser\Node\Identifier) {
            $prop_name = $stmt->name->name;
        } elseif (($stmt_name_type = $statements_analyzer->node_data->getType($stmt->name))
            && $stmt_name_type->isSingleStringLiteral()
        ) {
            $prop_name = $stmt_name_type->getSingleStringLiteral()->value;
        } else {
            $prop_name = null;
        }

        $codebase = $statements_analyzer->getCodebase();

        $stmt_var_id = ExpressionAnalyzer::getArrayVarId(
            $stmt->var,
            $statements_analyzer->getFQCLN(),
            $statements_analyzer
        );

        $var_id = ExpressionAnalyzer::getArrayVarId(
            $stmt,
            $statements_analyzer->getFQCLN(),
            $statements_analyzer
        );

        $stmt_type = null;

        if ($var_id && $context->hasVariable($var_id, $statements_analyzer)) {
            $stmt_type = $context->vars_in_scope[$var_id];

            // we don't need to check anything
            $statements_analyzer->node_data->setType($stmt, $stmt_type);

            if (!$context->collect_initializations
                && !$context->collect_mutations
                && $statements_analyzer->getFilePath() === $statements_analyzer->getRootFilePath()
                && (!(($parent_source = $statements_analyzer->getSource())
                        instanceof \Psalm\Internal\Analyzer\FunctionLikeAnalyzer)
                    || !$parent_source->getSource() instanceof \Psalm\Internal\Analyzer\TraitAnalyzer)
            ) {
                $codebase->analyzer->incrementNonMixedCount($statements_analyzer->getFilePath());
            }

            if ($codebase->store_node_types
                && !$context->collect_initializations
                && !$context->collect_mutations
            ) {
                $codebase->analyzer->addNodeType(
                    $statements_analyzer->getFilePath(),
                    $stmt->name,
                    $stmt_type->getId()
                );
            }

            if ($stmt_var_id === '$this'
                && !$stmt_type->initialized
                && $context->collect_initializations
                && ($stmt_var_type = $statements_analyzer->node_data->getType($stmt->var))
                && $stmt_var_type->hasObjectType()
                && $stmt->name instanceof PhpParser\Node\Identifier
            ) {
                $source = $statements_analyzer->getSource();

                $property_id = null;

                foreach ($stmt_var_type->getAtomicTypes() as $lhs_type_part) {
                    if ($lhs_type_part instanceof TNamedObject) {
                        if (!$codebase->classExists($lhs_type_part->value)) {
                            continue;
                        }

                        $property_id = $lhs_type_part->value . '::$' . $stmt->name->name;
                    }
                }

                if ($property_id
                    && $source instanceof FunctionLikeAnalyzer
                    && $source->getMethodName() === '__construct'
                    && !$context->inside_unset
                ) {
                    if ($context->inside_isset
                        || ($context->inside_assignment
                            && isset($context->vars_in_scope[$var_id])
                            && $context->vars_in_scope[$var_id]->isNullable()
                        )
                    ) {
                        $stmt_type->initialized = true;
                    } else {
                        if (IssueBuffer::accepts(
                            new UninitializedProperty(
                                'Cannot use uninitialized property ' . $var_id,
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $var_id
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }

                        $stmt_type->addType(new Type\Atomic\TNull);
                    }
                }
            }

            if (($stmt_var_type = $statements_analyzer->node_data->getType($stmt->var))
                && $stmt_var_type->hasObjectType()
                && $stmt->name instanceof PhpParser\Node\Identifier
            ) {
                // log the appearance
                foreach ($stmt_var_type->getAtomicTypes() as $lhs_type_part) {
                    if ($lhs_type_part instanceof TNamedObject) {
                        if (!$codebase->classExists($lhs_type_part->value)) {
                            continue;
                        }

                        $property_id = $lhs_type_part->value . '::$' . $stmt->name->name;

                        self::processTaints($statements_analyzer, $stmt, $stmt_type, $property_id);

                        $codebase->properties->propertyExists(
                            $property_id,
                            true,
                            $statements_analyzer,
                            $context,
                            $codebase->collect_locations
                                ? new CodeLocation($statements_analyzer->getSource(), $stmt)
                                : null
                        );

                        if ($codebase->store_node_types
                            && !$context->collect_initializations
                            && !$context->collect_mutations
                        ) {
                            $codebase->analyzer->addNodeReference(
                                $statements_analyzer->getFilePath(),
                                $stmt->name,
                                $property_id
                            );
                        }
                    }
                }
            }

            return null;
        }

        if ($stmt_var_id && $context->hasVariable($stmt_var_id, $statements_analyzer)) {
            $stmt_var_type = $context->vars_in_scope[$stmt_var_id];
        } else {
            $stmt_var_type = $statements_analyzer->node_data->getType($stmt->var);
        }

        if (!$stmt_var_type) {
            return null;
        }

        if ($stmt_var_type->isNull()) {
            if (IssueBuffer::accepts(
                new NullPropertyFetch(
                    'Cannot get property on null variable ' . $stmt_var_id,
                    new CodeLocation($statements_analyzer->getSource(), $stmt)
                ),
                $statements_analyzer->getSuppressedIssues()
            )) {
                return false;
            }

            return null;
        }

        if ($stmt_var_type->isEmpty()) {
            if (IssueBuffer::accepts(
                new MixedPropertyFetch(
                    'Cannot fetch property on empty var ' . $stmt_var_id,
                    new CodeLocation($statements_analyzer->getSource(), $stmt)
                ),
                $statements_analyzer->getSuppressedIssues()
            )) {
                return false;
            }

            return null;
        }

        if ($stmt_var_type->hasMixed()) {
            if (!$context->collect_initializations
                && !$context->collect_mutations
                && $statements_analyzer->getFilePath() === $statements_analyzer->getRootFilePath()
                && (!(($parent_source = $statements_analyzer->getSource())
                        instanceof \Psalm\Internal\Analyzer\FunctionLikeAnalyzer)
                    || !$parent_source->getSource() instanceof \Psalm\Internal\Analyzer\TraitAnalyzer)
            ) {
                $codebase->analyzer->incrementMixedCount($statements_analyzer->getFilePath());
            }

            if ($stmt->name instanceof PhpParser\Node\Identifier) {
                $codebase->analyzer->addMixedMemberName(
                    '$' . $stmt->name->name,
                    $context->calling_method_id ?: $statements_analyzer->getFileName()
                );
            }

            if (IssueBuffer::accepts(
                new MixedPropertyFetch(
                    'Cannot fetch property on mixed var ' . $stmt_var_id,
                    new CodeLocation($statements_analyzer->getSource(), $stmt)
                ),
                $statements_analyzer->getSuppressedIssues()
            )) {
                // fall through
            }

            $statements_analyzer->node_data->setType($stmt, Type::getMixed());

            if ($codebase->store_node_types
                && !$context->collect_initializations
                && !$context->collect_mutations
            ) {
                $codebase->analyzer->addNodeType(
                    $statements_analyzer->getFilePath(),
                    $stmt->name,
                    $stmt_var_type->getId()
                );
            }
        }

        if (!$context->collect_initializations
            && !$context->collect_mutations
            && $statements_analyzer->getFilePath() === $statements_analyzer->getRootFilePath()
            && (!(($parent_source = $statements_analyzer->getSource())
                    instanceof \Psalm\Internal\Analyzer\FunctionLikeAnalyzer)
                || !$parent_source->getSource() instanceof \Psalm\Internal\Analyzer\TraitAnalyzer)
        ) {
            $codebase->analyzer->incrementNonMixedCount($statements_analyzer->getRootFilePath());
        }

        if ($stmt_var_type->isNullable() && !$stmt_var_type->ignore_nullable_issues) {
            if (!$context->inside_isset) {
                if (IssueBuffer::accepts(
                    new PossiblyNullPropertyFetch(
                        'Cannot get property on possibly null variable ' . $stmt_var_id . ' of type ' . $stmt_var_type,
                        new CodeLocation($statements_analyzer->getSource(), $stmt)
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // fall through
                }
            } else {
                $statements_analyzer->node_data->setType($stmt, Type::getNull());
            }
        }

        if (!$prop_name) {
            if ($stmt_var_type->hasObjectType() && !$context->ignore_variable_property) {
                foreach ($stmt_var_type->getAtomicTypes() as $type) {
                    if ($type instanceof Type\Atomic\TNamedObject) {
                        $codebase->analyzer->addMixedMemberName(
                            strtolower($type->value) . '::$',
                            $context->calling_method_id ?: $statements_analyzer->getFileName()
                        );
                    }
                }
            }

            return null;
        }

        $invalid_fetch_types = [];
        $has_valid_fetch_type = false;

        foreach ($stmt_var_type->getAtomicTypes() as $lhs_type_part) {
            if ($lhs_type_part instanceof TNull) {
                continue;
            }

            if ($lhs_type_part instanceof Type\Atomic\TTemplateParam) {
                $extra_types = $lhs_type_part->extra_types;

                $lhs_type_part = array_values(
                    $lhs_type_part->as->getAtomicTypes()
                )[0];

                $lhs_type_part->from_docblock = true;

                if ($lhs_type_part instanceof TNamedObject) {
                    $lhs_type_part->extra_types = $extra_types;
                }
            }

            if ($lhs_type_part instanceof Type\Atomic\TMixed) {
                $statements_analyzer->node_data->setType($stmt, Type::getMixed());
                continue;
            }

            if ($lhs_type_part instanceof Type\Atomic\TFalse && $stmt_var_type->ignore_falsable_issues) {
                continue;
            }

            if (!$lhs_type_part instanceof TNamedObject && !$lhs_type_part instanceof TObject) {
                $invalid_fetch_types[] = (string)$lhs_type_part;

                continue;
            }

            $has_valid_fetch_type = true;

            if ($lhs_type_part instanceof TObjectWithProperties
                && isset($lhs_type_part->properties[$prop_name])
            ) {
                if ($stmt_type = $statements_analyzer->node_data->getType($stmt)) {
                    $statements_analyzer->node_data->setType(
                        $stmt,
                        Type::combineUnionTypes(
                            $lhs_type_part->properties[$prop_name],
                            $stmt_type
                        )
                    );
                } else {
                    $statements_analyzer->node_data->setType($stmt, $lhs_type_part->properties[$prop_name]);
                }

                continue;
            }

            // stdClass and SimpleXMLElement are special cases where we cannot infer the return types
            // but we don't want to throw an error
            // Hack has a similar issue: https://github.com/facebook/hhvm/issues/5164
            if ($lhs_type_part instanceof TObject
                || in_array(strtolower($lhs_type_part->value), ['stdclass', 'simplexmlelement'], true)
            ) {
                $statements_analyzer->node_data->setType($stmt, Type::getMixed());

                continue;
            }

            if (ExpressionAnalyzer::isMock($lhs_type_part->value)) {
                $statements_analyzer->node_data->setType($stmt, Type::getMixed());
                continue;
            }

            $intersection_types = $lhs_type_part->getIntersectionTypes() ?: [];

            $fq_class_name = $lhs_type_part->value;

            $override_property_visibility = false;

            $has_magic_getter = false;

            $class_exists = false;
            $interface_exists = false;

            if (!$codebase->classExists($lhs_type_part->value)) {
                if ($codebase->interfaceExists($lhs_type_part->value)) {
                    $interface_exists = true;
                    $interface_storage = $codebase->classlike_storage_provider->get($lhs_type_part->value);

                    $override_property_visibility = $interface_storage->override_property_visibility;

                    foreach ($intersection_types as $intersection_type) {
                        if ($intersection_type instanceof TNamedObject
                            && $codebase->classExists($intersection_type->value)
                        ) {
                            $fq_class_name = $intersection_type->value;
                            $class_exists = true;
                            break;
                        }
                    }

                    if (!$class_exists) {
                        if (IssueBuffer::accepts(
                            new NoInterfaceProperties(
                                'Interfaces cannot have properties',
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $lhs_type_part->value
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            return null;
                        }

                        if (!$codebase->methodExists($fq_class_name . '::__set')) {
                            return null;
                        }
                    }
                }

                if (!$class_exists && !$interface_exists) {
                    if ($lhs_type_part->from_docblock) {
                        if (IssueBuffer::accepts(
                            new UndefinedDocblockClass(
                                'Cannot set properties of undefined docblock class ' . $lhs_type_part->value,
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $lhs_type_part->value
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new UndefinedClass(
                                'Cannot set properties of undefined class ' . $lhs_type_part->value,
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $lhs_type_part->value
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }

                    return null;
                }
            } else {
                $class_exists = true;
            }

            $property_id = $fq_class_name . '::$' . $prop_name;

            $get_method_id = new \Psalm\Internal\MethodIdentifier($fq_class_name, '__get');
            if ($codebase->methods->methodExists($get_method_id)
                && (!$codebase->properties->propertyExists($property_id, true, $statements_analyzer, $context)
                    || ($stmt_var_id !== '$this'
                        && $fq_class_name !== $context->self
                        && ClassLikeAnalyzer::checkPropertyVisibility(
                            $property_id,
                            $context,
                            $statements_analyzer,
                            new CodeLocation($statements_analyzer->getSource(), $stmt),
                            $statements_analyzer->getSuppressedIssues(),
                            false
                        ) !== true)
                )
            ) {
                $has_magic_getter = true;

                $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);

                if (isset($class_storage->pseudo_property_get_types['$' . $prop_name])) {
                    $stmt_type = clone $class_storage->pseudo_property_get_types['$' . $prop_name];

                    $statements_analyzer->node_data->setType($stmt, $stmt_type);

                    self::processTaints($statements_analyzer, $stmt, $stmt_type, $property_id);
                    continue;
                }

                $old_data_provider = $statements_analyzer->node_data;

                $statements_analyzer->node_data = clone $statements_analyzer->node_data;

                $fake_method_call = new PhpParser\Node\Expr\MethodCall(
                    $stmt->var,
                    new PhpParser\Node\Identifier('__get', $stmt->name->getAttributes()),
                    [
                        new PhpParser\Node\Arg(
                            new PhpParser\Node\Scalar\String_(
                                $prop_name,
                                $stmt->name->getAttributes()
                            )
                        )
                    ]
                );

                $suppressed_issues = $statements_analyzer->getSuppressedIssues();

                if (!in_array('PossiblyNullReference', $suppressed_issues, true)) {
                    $statements_analyzer->addSuppressedIssues(['PossiblyNullReference']);
                }

                if (!in_array('InternalMethod', $suppressed_issues, true)) {
                    $statements_analyzer->addSuppressedIssues(['InternalMethod']);
                }

                \Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer::analyze(
                    $statements_analyzer,
                    $fake_method_call,
                    $context,
                    false
                );

                if (!in_array('PossiblyNullReference', $suppressed_issues, true)) {
                    $statements_analyzer->removeSuppressedIssues(['PossiblyNullReference']);
                }

                if (!in_array('InternalMethod', $suppressed_issues, true)) {
                    $statements_analyzer->removeSuppressedIssues(['InternalMethod']);
                }

                $fake_method_call_type = $statements_analyzer->node_data->getType($fake_method_call);

                $statements_analyzer->node_data = $old_data_provider;

                if ($fake_method_call_type) {
                    $statements_analyzer->node_data->setType($stmt, $fake_method_call_type);
                } else {
                    $statements_analyzer->node_data->setType($stmt, Type::getMixed());
                }

                $property_id = $lhs_type_part->value . '::$' . $prop_name;

                /*
                 * If we have an explicit list of all allowed magic properties on the class, and we're
                 * not in that list, fall through
                 */
                if (!$class_storage->sealed_properties && !$override_property_visibility) {
                    continue;
                }

                if (!$class_exists) {
                    if (IssueBuffer::accepts(
                        new UndefinedMagicPropertyFetch(
                            'Magic instance property ' . $property_id . ' is not defined',
                            new CodeLocation($statements_analyzer->getSource(), $stmt),
                            $property_id
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }
            }

            if (!$class_exists) {
                continue;
            }

            if ($codebase->store_node_types
                && !$context->collect_initializations
                && !$context->collect_mutations
            ) {
                $codebase->analyzer->addNodeReference(
                    $statements_analyzer->getFilePath(),
                    $stmt->name,
                    $property_id
                );
            }

            $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
            $config = $statements_analyzer->getProjectAnalyzer()->getConfig();

            if (!$codebase->properties->propertyExists(
                $property_id,
                true,
                $statements_analyzer,
                $context,
                $codebase->collect_locations ? new CodeLocation($statements_analyzer->getSource(), $stmt) : null
            )
            ) {
                if ($config->use_phpdoc_property_without_magic_or_parent
                    && isset($class_storage->pseudo_property_get_types['$' . $prop_name])
                ) {
                    $stmt_type = clone $class_storage->pseudo_property_get_types['$' . $prop_name];

                    $statements_analyzer->node_data->setType($stmt, $stmt_type);

                    self::processTaints($statements_analyzer, $stmt, $stmt_type, $property_id);
                    continue;
                }

                if ($fq_class_name !== $context->self
                    && $context->self
                    && $codebase->properties->propertyExists(
                        $context->self . '::$' . $prop_name,
                        true,
                        $statements_analyzer,
                        $context,
                        $codebase->collect_locations
                            ? new CodeLocation($statements_analyzer->getSource(), $stmt)
                            : null
                    )
                ) {
                    $property_id = $context->self . '::$' . $prop_name;
                } else {
                    if ($context->inside_isset || $context->collect_initializations) {
                        return;
                    }

                    if ($stmt_var_id === '$this') {
                        if (IssueBuffer::accepts(
                            new UndefinedThisPropertyFetch(
                                'Instance property ' . $property_id . ' is not defined',
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $property_id
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if ($has_magic_getter) {
                            if (IssueBuffer::accepts(
                                new UndefinedMagicPropertyFetch(
                                    'Magic instance property ' . $property_id . ' is not defined',
                                    new CodeLocation($statements_analyzer->getSource(), $stmt),
                                    $property_id
                                ),
                                $statements_analyzer->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new UndefinedPropertyFetch(
                                    'Instance property ' . $property_id . ' is not defined',
                                    new CodeLocation($statements_analyzer->getSource(), $stmt),
                                    $property_id
                                ),
                                $statements_analyzer->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }

                    $stmt_type = Type::getMixed();

                    $statements_analyzer->node_data->setType($stmt, $stmt_type);

                    if ($var_id) {
                        $context->vars_in_scope[$var_id] = $stmt_type;
                    }

                    return;
                }
            }

            if (!$override_property_visibility) {
                if (ClassLikeAnalyzer::checkPropertyVisibility(
                    $property_id,
                    $context,
                    $statements_analyzer,
                    new CodeLocation($statements_analyzer->getSource(), $stmt),
                    $statements_analyzer->getSuppressedIssues()
                ) === false) {
                    return false;
                }
            }

            $declaring_property_class = $codebase->properties->getDeclaringClassForProperty(
                $property_id,
                true,
                $statements_analyzer
            );

            if ($declaring_property_class === null) {
                continue;
            }

            if ($codebase->properties_to_rename) {
                $declaring_property_id = strtolower($declaring_property_class) . '::$' . $prop_name;

                foreach ($codebase->properties_to_rename as $original_property_id => $new_property_name) {
                    if ($declaring_property_id === $original_property_id) {
                        $file_manipulations = [
                            new \Psalm\FileManipulation(
                                (int) $stmt->name->getAttribute('startFilePos'),
                                (int) $stmt->name->getAttribute('endFilePos') + 1,
                                $new_property_name
                            )
                        ];

                        \Psalm\Internal\FileManipulation\FileManipulationBuffer::add(
                            $statements_analyzer->getFilePath(),
                            $file_manipulations
                        );
                    }
                }
            }

            $declaring_class_storage = $codebase->classlike_storage_provider->get(
                $declaring_property_class
            );

            if (isset($declaring_class_storage->properties[$prop_name])) {
                $property_storage = $declaring_class_storage->properties[$prop_name];

                if ($property_storage->deprecated) {
                    if (IssueBuffer::accepts(
                        new DeprecatedProperty(
                            $property_id . ' is marked deprecated',
                            new CodeLocation($statements_analyzer->getSource(), $stmt),
                            $property_id
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if ($property_storage->psalm_internal && $context->self) {
                    if (! NamespaceAnalyzer::isWithin($context->self, $property_storage->psalm_internal)) {
                        if (IssueBuffer::accepts(
                            new InternalProperty(
                                $property_id . ' is marked internal to ' . $property_storage->psalm_internal,
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $property_id
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }

                if ($property_storage->internal && $context->self) {
                    if (! NamespaceAnalyzer::nameSpaceRootsMatch($context->self, $declaring_property_class)) {
                        if (IssueBuffer::accepts(
                            new InternalProperty(
                                $property_id . ' is marked internal',
                                new CodeLocation($statements_analyzer->getSource(), $stmt),
                                $property_id
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            $class_property_type = $codebase->properties->getPropertyType(
                $property_id,
                false,
                $statements_analyzer,
                $context
            );

            if (!$class_property_type) {
                if ($declaring_class_storage->location
                    && $config->isInProjectDirs(
                        $declaring_class_storage->location->file_path
                    )
                ) {
                    if (IssueBuffer::accepts(
                        new MissingPropertyType(
                            'Property ' . $fq_class_name . '::$' . $prop_name
                                . ' does not have a declared type',
                            new CodeLocation($statements_analyzer->getSource(), $stmt)
                        ),
                        $statements_analyzer->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $class_property_type = Type::getMixed();
            } else {
                $class_property_type = ExpressionAnalyzer::fleshOutType(
                    $codebase,
                    clone $class_property_type,
                    $declaring_class_storage->name,
                    $declaring_class_storage->name,
                    $declaring_class_storage->parent_class
                );

                if ($declaring_class_storage->template_types) {
                    if (!$lhs_type_part instanceof TGenericObject) {
                        $type_params = [];

                        foreach ($declaring_class_storage->template_types as $type_map) {
                            $type_params[] = clone array_values($type_map)[0][0];
                        }

                        $lhs_type_part = new TGenericObject($lhs_type_part->value, $type_params);
                    }

                    $class_property_type = self::localizePropertyType(
                        $codebase,
                        $class_property_type,
                        $lhs_type_part,
                        $class_storage,
                        $declaring_class_storage
                    );
                }

                if ($lhs_type_part instanceof TGenericObject) {
                    $class_property_type = self::localizePropertyType(
                        $codebase,
                        $class_property_type,
                        $lhs_type_part,
                        $class_storage,
                        $declaring_class_storage
                    );
                }
            }

            self::processTaints($statements_analyzer, $stmt, $class_property_type, $property_id);

            if ($stmt_type = $statements_analyzer->node_data->getType($stmt)) {
                $statements_analyzer->node_data->setType(
                    $stmt,
                    Type::combineUnionTypes($class_property_type, $stmt_type)
                );
            } else {
                $statements_analyzer->node_data->setType($stmt, $class_property_type);
            }
        }

        $stmt_type = $statements_analyzer->node_data->getType($stmt);

        if ($stmt_var_type->isNullable() && !$context->inside_isset && $stmt_type) {
            $stmt_type->addType(new TNull);

            if ($stmt_var_type->ignore_nullable_issues) {
                $stmt_type->ignore_nullable_issues = true;
            }
        }

        if ($codebase->store_node_types
            && !$context->collect_initializations
            && !$context->collect_mutations
            && ($stmt_type = $statements_analyzer->node_data->getType($stmt))
        ) {
            $codebase->analyzer->addNodeType(
                $statements_analyzer->getFilePath(),
                $stmt->name,
                $stmt_type->getId()
            );
        }

        if ($invalid_fetch_types) {
            $lhs_type_part = $invalid_fetch_types[0];

            if ($has_valid_fetch_type) {
                if (IssueBuffer::accepts(
                    new PossiblyInvalidPropertyFetch(
                        'Cannot fetch property on possible non-object ' . $stmt_var_id . ' of type ' . $lhs_type_part,
                        new CodeLocation($statements_analyzer->getSource(), $stmt)
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // fall through
                }
            } else {
                if (IssueBuffer::accepts(
                    new InvalidPropertyFetch(
                        'Cannot fetch property on non-object ' . $stmt_var_id . ' of type ' . $lhs_type_part,
                        new CodeLocation($statements_analyzer->getSource(), $stmt)
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }

        if ($var_id) {
            $context->vars_in_scope[$var_id] = $statements_analyzer->node_data->getType($stmt) ?: Type::getMixed();
        }
    }

    public static function localizePropertyType(
        \Psalm\Codebase $codebase,
        Type\Union $class_property_type,
        TGenericObject $lhs_type_part,
        ClassLikeStorage $calling_class_storage,
        ClassLikeStorage $declaring_class_storage
    ) : Type\Union {
        $template_types = CallAnalyzer::getTemplateTypesForCall(
            $declaring_class_storage,
            $calling_class_storage,
            $calling_class_storage->template_types ?: []
        );

        $extended_types = $calling_class_storage->template_type_extends;

        if ($template_types) {
            if ($calling_class_storage->template_types) {
                foreach ($lhs_type_part->type_params as $param_offset => $lhs_param_type) {
                    $i = -1;

                    foreach ($calling_class_storage->template_types as $calling_param_name => $_) {
                        $i++;

                        if ($i === $param_offset) {
                            $template_types[$calling_param_name][$calling_class_storage->name] = [
                                $lhs_param_type,
                                0
                            ];
                            break;
                        }
                    }
                }
            }

            foreach ($template_types as $type_name => $_) {
                if (isset($extended_types[$declaring_class_storage->name][$type_name])) {
                    $mapped_type = $extended_types[$declaring_class_storage->name][$type_name];

                    foreach ($mapped_type->getAtomicTypes() as $mapped_type_atomic) {
                        if (!$mapped_type_atomic instanceof Type\Atomic\TTemplateParam) {
                            continue;
                        }

                        $param_name = $mapped_type_atomic->param_name;

                        $position = false;

                        if (isset($calling_class_storage->template_types[$param_name])) {
                            $position = \array_search(
                                $param_name,
                                array_keys($calling_class_storage->template_types)
                            );
                        }

                        if ($position !== false && isset($lhs_type_part->type_params[$position])) {
                            $template_types[$type_name][$declaring_class_storage->name] = [
                                $lhs_type_part->type_params[$position],
                                0
                            ];
                        }
                    }
                }
            }

            $class_property_type->replaceTemplateTypesWithArgTypes(
                new TemplateResult([], $template_types),
                $codebase
            );
        }

        return $class_property_type;
    }

    private static function processTaints(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\PropertyFetch $stmt,
        Type\Union $type,
        string $property_id
    ) : void {
        $codebase = $statements_analyzer->getCodebase();

        if ($codebase->taint) {
            $method_source = new Source(
                $property_id,
                $property_id,
                new CodeLocation($statements_analyzer, $stmt->name)
            );

            $type->sources = [$method_source];

            if ($tainted_source = $codebase->taint->hasPreviousSource($method_source)) {
                $type->tainted = $tainted_source->taint;
                $method_source->taint = $type->tainted;
            }
        }
    }

    /**
     * @param   StatementsAnalyzer                       $statements_analyzer
     * @param   PhpParser\Node\Expr\StaticPropertyFetch $stmt
     * @param   Context                                 $context
     *
     * @return  null|false
     */
    public static function analyzeStatic(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\StaticPropertyFetch $stmt,
        Context $context
    ) {
        if ($stmt->class instanceof PhpParser\Node\Expr\Variable ||
            $stmt->class instanceof PhpParser\Node\Expr\ArrayDimFetch
        ) {
            // @todo check this
            return null;
        }

        $fq_class_name = null;

        $codebase = $statements_analyzer->getCodebase();

        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (count($stmt->class->parts) === 1
                && in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if ($stmt->class->parts[0] === 'parent') {
                    $fq_class_name = $statements_analyzer->getParentFQCLN();

                    if ($fq_class_name === null) {
                        if (IssueBuffer::accepts(
                            new ParentNotFound(
                                'Cannot check property fetch on parent as this class does not extend another',
                                new CodeLocation($statements_analyzer->getSource(), $stmt)
                            ),
                            $statements_analyzer->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return;
                    }
                } else {
                    $fq_class_name = (string)$context->self;
                }

                if ($context->isPhantomClass($fq_class_name)) {
                    return null;
                }
            } else {
                $aliases = $statements_analyzer->getAliases();

                if ($context->calling_method_id
                    && !$stmt->class instanceof PhpParser\Node\Name\FullyQualified
                ) {
                    $codebase->file_reference_provider->addMethodReferenceToClassMember(
                        $context->calling_method_id,
                        'use:' . $stmt->class->parts[0] . ':' . \md5($statements_analyzer->getFilePath())
                    );
                }

                $fq_class_name = ClassLikeAnalyzer::getFQCLNFromNameObject(
                    $stmt->class,
                    $aliases
                );

                if ($context->isPhantomClass($fq_class_name)) {
                    return null;
                }

                if ($context->check_classes) {
                    if (ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                        $statements_analyzer,
                        $fq_class_name,
                        new CodeLocation($statements_analyzer->getSource(), $stmt->class),
                        $context->self,
                        $context->calling_method_id,
                        $statements_analyzer->getSuppressedIssues(),
                        false
                    ) !== true) {
                        return false;
                    }
                }
            }

            if ($fq_class_name
                && $codebase->methods_to_move
                && $context->calling_method_id
                && isset($codebase->methods_to_move[$context->calling_method_id])
            ) {
                $destination_method_id = $codebase->methods_to_move[$context->calling_method_id];

                $codebase->classlikes->airliftClassLikeReference(
                    $fq_class_name,
                    explode('::', $destination_method_id)[0],
                    $statements_analyzer->getFilePath(),
                    (int) $stmt->class->getAttribute('startFilePos'),
                    (int) $stmt->class->getAttribute('endFilePos') + 1
                );
            }

            if ($fq_class_name) {
                $statements_analyzer->node_data->setType(
                    $stmt->class,
                    new Type\Union([new TNamedObject($fq_class_name)])
                );
            }
        }

        if ($stmt->name instanceof PhpParser\Node\VarLikeIdentifier) {
            $prop_name = $stmt->name->name;
        } elseif (($stmt_name_type = $statements_analyzer->node_data->getType($stmt->name))
            && $stmt_name_type->isSingleStringLiteral()
        ) {
            $prop_name = $stmt_name_type->getSingleStringLiteral()->value;
        } else {
            $prop_name = null;
        }

        if (!$prop_name) {
            if ($fq_class_name) {
                $codebase->analyzer->addMixedMemberName(
                    strtolower($fq_class_name) . '::$',
                    $context->calling_method_id ?: $statements_analyzer->getFileName()
                );
            }

            return null;
        }

        if (!$fq_class_name
            || !$context->check_classes
            || !$context->check_variables
            || ExpressionAnalyzer::isMock($fq_class_name)
        ) {
            return null;
        }

        $var_id = ExpressionAnalyzer::getVarId(
            $stmt,
            $context->self ?: $statements_analyzer->getFQCLN(),
            $statements_analyzer
        );

        $property_id = $fq_class_name . '::$' . $prop_name;

        if ($codebase->store_node_types
            && !$context->collect_initializations
            && !$context->collect_mutations
        ) {
            $codebase->analyzer->addNodeReference(
                $statements_analyzer->getFilePath(),
                $stmt->name,
                $property_id
            );
        }

        if ($context->mutation_free) {
            if (IssueBuffer::accepts(
                new \Psalm\Issue\ImpureStaticProperty(
                    'Cannot use a static property in a mutation-free context',
                    new CodeLocation($statements_analyzer, $stmt)
                ),
                $statements_analyzer->getSuppressedIssues()
            )) {
                // fall through
            }
        }

        if ($var_id && $context->hasVariable($var_id, $statements_analyzer)) {
            $stmt_type = $context->vars_in_scope[$var_id];

            // we don't need to check anything
            $statements_analyzer->node_data->setType($stmt, $stmt_type);

            if ($codebase->collect_references) {
                // log the appearance
                $codebase->properties->propertyExists(
                    $property_id,
                    true,
                    $statements_analyzer,
                    $context,
                    $codebase->collect_locations
                        ? new CodeLocation($statements_analyzer->getSource(), $stmt)
                        : null
                );
            }

            if ($codebase->store_node_types
                && !$context->collect_initializations
                && !$context->collect_mutations
                && ($stmt_type = $statements_analyzer->node_data->getType($stmt))
            ) {
                $codebase->analyzer->addNodeType(
                    $statements_analyzer->getFilePath(),
                    $stmt->name,
                    $stmt_type->getId()
                );
            }

            return null;
        }

        if (!$codebase->properties->propertyExists(
            $property_id,
            true,
            $statements_analyzer,
            $context,
            $codebase->collect_locations
                ? new CodeLocation($statements_analyzer->getSource(), $stmt)
                : null
        )
        ) {
            if (IssueBuffer::accepts(
                new UndefinedPropertyFetch(
                    'Static property ' . $property_id . ' is not defined',
                    new CodeLocation($statements_analyzer->getSource(), $stmt),
                    $property_id
                ),
                $statements_analyzer->getSuppressedIssues()
            )) {
                // fall through
            }

            return;
        }

        if (ClassLikeAnalyzer::checkPropertyVisibility(
            $property_id,
            $context,
            $statements_analyzer,
            new CodeLocation($statements_analyzer->getSource(), $stmt),
            $statements_analyzer->getSuppressedIssues()
        ) === false) {
            return false;
        }

        $declaring_property_class = $codebase->properties->getDeclaringClassForProperty(
            $fq_class_name . '::$' . $prop_name,
            true,
            $statements_analyzer
        );

        if ($declaring_property_class === null) {
            return false;
        }

        $declaring_property_id = strtolower($declaring_property_class) . '::$' . $prop_name;

        if ($codebase->alter_code && $stmt->class instanceof PhpParser\Node\Name) {
            $moved_class = $codebase->classlikes->handleClassLikeReferenceInMigration(
                $codebase,
                $statements_analyzer,
                $stmt->class,
                $fq_class_name,
                $context->calling_method_id
            );

            if (!$moved_class) {
                foreach ($codebase->property_transforms as $original_pattern => $transformation) {
                    if ($declaring_property_id === $original_pattern) {
                        list($old_declaring_fq_class_name) = explode('::$', $declaring_property_id);
                        list($new_fq_class_name, $new_property_name) = explode('::$', $transformation);

                        $file_manipulations = [];

                        if (strtolower($new_fq_class_name) !== strtolower($old_declaring_fq_class_name)) {
                            $file_manipulations[] = new \Psalm\FileManipulation(
                                (int) $stmt->class->getAttribute('startFilePos'),
                                (int) $stmt->class->getAttribute('endFilePos') + 1,
                                Type::getStringFromFQCLN(
                                    $new_fq_class_name,
                                    $statements_analyzer->getNamespace(),
                                    $statements_analyzer->getAliasedClassesFlipped(),
                                    null
                                )
                            );
                        }

                        $file_manipulations[] = new \Psalm\FileManipulation(
                            (int) $stmt->name->getAttribute('startFilePos'),
                            (int) $stmt->name->getAttribute('endFilePos') + 1,
                            '$' . $new_property_name
                        );

                        FileManipulationBuffer::add($statements_analyzer->getFilePath(), $file_manipulations);
                    }
                }
            }
        }

        $class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        $property = $class_storage->properties[$prop_name];

        if ($var_id) {
            if ($property->type) {
                $context->vars_in_scope[$var_id] = ExpressionAnalyzer::fleshOutType(
                    $codebase,
                    clone $property->type,
                    $class_storage->name,
                    $class_storage->name,
                    $class_storage->parent_class
                );
            } else {
                $context->vars_in_scope[$var_id] = Type::getMixed();
            }

            $stmt_type = clone $context->vars_in_scope[$var_id];

            $statements_analyzer->node_data->setType($stmt, $stmt_type);

            if ($codebase->store_node_types
                && !$context->collect_initializations
                && !$context->collect_mutations
            ) {
                $codebase->analyzer->addNodeType(
                    $statements_analyzer->getFilePath(),
                    $stmt->name,
                    $stmt_type->getId()
                );
            }
        } else {
            $statements_analyzer->node_data->setType($stmt, Type::getMixed());
        }

        return null;
    }
}
