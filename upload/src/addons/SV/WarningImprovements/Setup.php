<?php

namespace SV\WarningImprovements;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\Entity\User;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        $sm = $this->schemaManager();

        foreach ($this->getTables() as $tableName => $callback)
        {
            $sm->createTable($tableName, $callback);
        }

        foreach ($this->getAlterTables() as $tableName => $callback)
        {
            $sm->alterTable($tableName, $callback);
        }
    }

    public function installStep2()
    {
        $db = $this->db();

        // insert the defaults for the custom warning. This can't be normally inserted so fiddle with the sql_mode
        $db->query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO'");
        $db->query("INSERT IGNORE INTO xf_warning_definition
                    (warning_definition_id,points_default,expiry_type,expiry_default,extra_user_group_ids,is_editable, sv_custom_title)
                VALUES
                    (0,1, 'months',1,'',1, 1);
            ");
        $db->query("SET SESSION sql_mode='STRICT_ALL_TABLES'");
    }

    public function installStep3()
    {
        $db = $this->db();

        // create default warning category, do not use the data writer as that requires the rest of the add-on to be setup
        $db->query("INSERT IGNORE INTO xf_sv_warning_category (warning_category_id, parent_category_id, display_order, allowed_user_group_ids)
                VALUES (1, null, 0, ?)
            ", [User::GROUP_REG]);
    }

    public function installStep4()
    {
        $db = $this->db();

        // set all warning definitions to be in default warning category, note; the phrase is defined in the XML
        $db->query('UPDATE xf_warning_definition
            SET sv_warning_category_id = 1
            WHERE sv_warning_category_id is null OR
                  NOT exists (SELECT *
                              FROM xf_sv_warning_category
                              WHERE xf_warning_definition.sv_warning_category_id = xf_sv_warning_category.warning_category_id)
        ');
    }

    public function upgrade2000000Step1()
    {
        $this->installStep1();

        $sm = $this->schemaManager();

        foreach ($this->getTables() as $tableName => $callback)
        {
            $sm->alterTable($tableName, $callback);
        }

        foreach ($this->getAlterTables() as $tableName => $callback)
        {
            $sm->alterTable($tableName, $callback);
        }
    }

    public function upgrade2000000Step2()
    {
        $db = $this->db();

        $db->query('
            UPDATE xf_sv_warning_category
            SET parent_category_id = NULL
            WHERE parent_category_id = 0'
        );

        $db->query('
            UPDATE xf_warning_definition
            SET sv_warning_category_id = NULL
            WHERE sv_warning_category_id = 0'
        );

        $db->query('
            UPDATE xf_warning_action
            SET sv_warning_category_id = NULL
            WHERE sv_warning_category_id = 0'
        );

        $db->query('
            UPDATE xf_warning_action
            SET sv_post_node_id = NULL
            WHERE sv_post_node_id = 0'
        );

        $db->query('
            UPDATE xf_warning_action
            SET sv_post_thread_id = NULL
            WHERE sv_post_thread_id = 0'
        );

        $db->query('
            UPDATE xf_warning_action
            SET sv_post_as_user_id = NULL
            WHERE sv_post_as_user_id = 0'
        );
    }

    public function upgrade2000000Step3()
    {
        $this->installStep4();
    }

    public function upgrade2000000Step4()
    {
        $map = [
            'sv_warning_category_*_title' => 'sv_warning_category_title.*'
        ];

        $db = $this->db();

        foreach ($map AS $from => $to)
        {
            $mySqlRegex = '^' . str_replace('*', '[a-zA-Z0-9_]+', $from) . '$';
            $phpRegex = '/^' . str_replace('*', '([a-zA-Z0-9_]+)', $from) . '$/';
            $replace = str_replace('*', '$1', $to);

            $results = $db->fetchPairs("
				SELECT phrase_id, title
				FROM xf_phrase
				WHERE title RLIKE ?
					AND addon_id = ''
			", $mySqlRegex);

            if ($results)
            {
                /** @var \XF\Entity\Phrase[] $phrases */
                $phrases = \XF::em()->findByIds('XF:Phrase', array_keys($results));
                foreach ($results AS $phraseId => $oldTitle)
                {
                    if (isset($phrases[$phraseId]))
                    {
                        $newTitle = preg_replace($phpRegex, $replace, $oldTitle);

                        $phrase = $phrases[$phraseId];
                        $phrase->title = $newTitle;
                        $phrase->global_cache = false;
                        $phrase->save(false);
                    }
                }
            }
        }
    }

    public function uninstallStep1()
    {
        $sm = $this->schemaManager();

        foreach ($this->getTables() as $tableName => $callback)
        {
            $sm->dropTable($tableName);
        }
    }

    public function uninstallStep2()
    {
        $sm = $this->schemaManager();

        foreach ($this->getRemoveAlterTables() as $tableName => $callback)
        {
            $sm->alterTable($tableName, $callback);
        }
    }

    /**
     * @return array
     */
    protected function getTables()
    {
        $tables = [];

        $tables['xf_sv_warning_default'] = function ($table)
        {
            /** @var Create|Alter $table */
            if ($table instanceof Create)
            {
                $table->checkExists(true);
            }

            $table->addColumn('warning_default_id', 'int')->autoIncrement();
            $table->addColumn('threshold_points', 'smallint')->setDefault(0);
            $table->addColumn('expiry_type', 'enum')->values(['never', 'days', 'weeks', 'months', 'years'])->setDefault('never');
            $table->addColumn('expiry_extension', 'smallint')->setDefault(0);
            $table->addColumn('active', 'tinyint', 3)->setDefault(1);

            $table->addPrimaryKey('warning_default_id');
        };

        $tables['xf_sv_warning_category'] = function ($table)
        {
            /** @var Create|Alter $table */
            if ($table instanceof Create)
            {
                $table->checkExists(true);
            }

            $table->addColumn('warning_category_id', 'int')->autoIncrement();
            $table->addColumn('parent_category_id', 'int')->nullable(true)->setDefault(null);
            $table->addColumn('display_order', 'int')->setDefault(0);
            $table->addColumn('lft', 'int')->setDefault(0);
            $table->addColumn('rgt', 'int')->setDefault(0);
            $table->addColumn('depth', 'smallint', 5)->setDefault(0);
            $table->addColumn('breadcrumb_data', 'blob');
            $table->addColumn('warning_count', 'int')->setDefault(0);
            $table->addColumn('allowed_user_group_ids', 'varbinary', 255)->setDefault(strval(User::GROUP_REG));

            $table->addPrimaryKey('warning_category_id');
            $table->addKey(['parent_category_id', 'lft']);
            $table->addKey(['lft', 'rgt']);
        };

        return $tables;
    }

    /**
     * @return array
     */
    protected function getAlterTables()
    {
        $tables = [];

        $tables['xf_user_option'] = function (Alter $table)
        {
            $table->addColumn('sv_pending_warning_expiry', 'int')->nullable(true)->setDefault(null);
        };

        $tables['xf_warning_definition'] = function (Alter $table)
        {
            $table->addColumn('sv_warning_category_id', 'int')->nullable(true)->setDefault(null);
            $table->addColumn('sv_display_order', 'int')->setDefault(0);
            $table->addColumn('sv_custom_title', 'tinyint', 1)->setDefault(0);
        };

        $tables['xf_warning_action'] = function (Alter $table)
        {
            $table->addColumn('sv_warning_category_id', 'int')->nullable(true)->setDefault(null);
            $table->addColumn('sv_post_node_id', 'int')->nullable(true)->setDefault(null);
            $table->addColumn('sv_post_thread_id', 'int')->nullable(true)->setDefault(null);
            $table->addColumn('sv_post_as_user_id', 'int')->nullable(true)->setDefault(null);
        };

        $tables['xf_sv_warning_category'] = function (Alter $table)
        {
            $table->renameColumn('parent_warning_category_id', 'parent_category_id')
                  ->nullable(true)
                  ->setDefault(null);
        };

        return $tables;
    }

    protected function getRemoveAlterTables()
    {
        $tables = [];

        $tables['xf_user_option'] = function (Alter $table)
        {
            $table->dropColumns('sv_pending_warning_expiry');
        };

        $tables['xf_warning_definition'] = function (Alter $table)
        {
            $table->dropColumns(['sv_warning_category_id', 'sv_display_order', 'sv_custom_title']);
        };

        $tables['xf_warning_definition'] = function (Alter $table)
        {
            $table->dropColumns('sv_warning_category_id');
        };

        $tables['xf_warning_action'] = function (Alter $table)
        {
            $table->dropColumns(['sv_warning_category_id', 'sv_post_node_id', 'sv_post_thread_id', 'sv_post_as_user_id']);
        };

        return $tables;
    }
}
