<?php

namespace redaxo_module_template_synch;

use rex_logger, rex_path, rex_sql, rex_file, PDO, DateTime;

class TemplateModuleSyncService
{
    private static $ext = 'php';
    private static $tempFiles = 'arr';

    public static function doSync(string $name)
    {
        if (true) {
            $dir = rex_path::addonData($name);
            self::writeTableToFiles('module', $dir);
            self::writeTableToFiles('template', $dir);

            $templates = self::getTemplatesFromFile($dir);
            $modules = self::getModulesFromFile($dir);

            self::extractInputOutput($dir, $modules);
            self::extractContent($dir, $templates);

            self::syncTemplate($dir, $templates);
            self::syncModules($dir, $modules);
        }
    }

    private static function syncTemplate(string $dir, $templates): void
    {
        foreach ($templates as $name => $template) {
            $outputFilepath = self::getTemplateContentFilepath($name, $dir);
            $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $template['rex_template.updatedate']);
            $fileTemplateUpdateTS = DateTime::createFromFormat('U', filemtime($outputFilepath));

            if ($dbUpdateTS <  $fileTemplateUpdateTS) {


                $tmp = array();
                $id = '';

                foreach ($template as $key => $value) {
                    if (str_contains($key, 'id')) {
                        $id = $value;
                    } else
                    if (!is_numeric($key)) {

                        $newKey = $key;
                        if (str_contains($newKey, '.')) {
                            $dotPosition = strpos($key, '.');
                            $newKey = substr($key, $dotPosition + 1);
                        }


                        $tmp[$newKey] = $value;
                        if ($newKey == 'content') {
                            $tmp['content'] = rex_file::get($outputFilepath);
                        }
                    }
                }
                //  dump($id);
                $sql = rex_sql::factory();
                $sql->setTable('rex_template');
                $sql->setValues($tmp);
                $sql->setWhere('id = ' . $id);
                $sql->update();
                // dump($sql->getRows());
                // dump($sql->getLastId());
            }
        }
    }


    private static function syncModules(string $dir, $modules): void
    {
        foreach ($modules as $name => $module) {
            $inputPath = self::getModuleFilepath($name, $dir, 'input');
            $outputPath = self::getModuleFilepath($name, $dir, 'output');
            $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $module['rex_module.updatedate']);
            $fileInputUpdateTS = DateTime::createFromFormat('U', filemtime($inputPath));
            $fileOutputUpdateTS = DateTime::createFromFormat('U', filemtime($outputPath));

            if ($dbUpdateTS <  $fileInputUpdateTS || $dbUpdateTS <  $fileOutputUpdateTS) {
                $tmp = array();
                $id = '';

                foreach ($module as $key => $value) {
                    if (str_contains($key, 'id')) {
                        $id = $value;
                    } else
                    if (!is_numeric($key)) {
                        $newKey = $key;
                        if (str_contains($newKey, '.')) {
                            $dotPosition = strpos($key, '.');
                            $newKey = substr($key, $dotPosition + 1);
                        }
                        $tmp[$newKey] = $value;
                        if ($newKey == 'input') {
                            $tmp['input'] = rex_file::get($inputPath);
                        } else if ($newKey == 'output') {
                            $tmp['output'] = rex_file::get($outputPath);
                        }
                    }
                }
                //  dump($id);
                $sql = rex_sql::factory();
                $sql->setTable('rex_module');
                $sql->setValues($tmp);
                $sql->setWhere('id = ' . $id);
                $sql->update();
                // dump($sql->getRows());
                // dump($sql->getLastId());
            }
        }
    }



    private static function getTemplatesFromFile(string $dir): array
    {
        $rex = join(DIRECTORY_SEPARATOR, array($dir . 'template', "*." . self::$tempFiles));
        $files = glob($rex);
        $templates = array();
        foreach ($files as $text) {
            $content = unserialize(rex_file::get($text, ''));
            $name = $content['rex_template.name'];
            $name = self::sanetizeName($name);
            $templates[$name] = $content;
        }
        return $templates;
    }

    private static function getModulesFromFile(string $dir): array
    {
        $modules = array();
        $rex = join(DIRECTORY_SEPARATOR, array($dir . 'module', "*." . self::$tempFiles));
        $files = glob($rex);
        foreach ($files as $text) {
            $content = unserialize(rex_file::get($text, ''));
            $name = $content['rex_module.name'];
            $modules[$name] = $content;
        }
        return $modules;
    }

    private static function extractContent(string $dir, $templates): void
    {
        foreach ($templates as $name => $template) {
            $templateContent = $template['rex_template.content'];

            $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $template['rex_template.updatedate']);
            $outputFilepath = self::getTemplateContentFilepath($name, $dir);
            if (file_exists($outputFilepath)) {
                $fileTemplateUpdateTS = DateTime::createFromFormat('U', filemtime($outputFilepath));

                if ($dbUpdateTS > $fileTemplateUpdateTS) {
                    rex_file::put($outputFilepath, $templateContent);
                }
            } else {
                rex_file::put($outputFilepath, $templateContent);
            }
        }
    }

    private static function getTemplateContentFilepath($name, string $dir): string
    {
        $name = self::sanetizeName($name);
        $workDir = join(DIRECTORY_SEPARATOR, array($dir . 'work', 'template', $name));
        return  join(DIRECTORY_SEPARATOR, array($workDir, 'content.' . self::$ext));
    }

    private static function getModuleFilepath($name, $dir, $art): string
    {
        $name = self::sanetizeName($name);
        $workDir = join(DIRECTORY_SEPARATOR, array($dir . 'work', 'module', $name));
        return join(DIRECTORY_SEPARATOR, array($workDir, $art . '.' . self::$ext));
    }

    private static function extractInputOutput(string $dir, $modules)
    {
        foreach ($modules as $name => $module) {
            $input = $module['rex_module.input'];
            $output = $module['rex_module.output'];

            $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $module['rex_module.updatedate']);

            $inputFilepath  = self::getModuleFilepath($name, $dir, 'input');
            if (file_exists($inputFilepath)) {
                $fileInputUpdateTS = DateTime::createFromFormat('U', filemtime($inputFilepath));
                if ($dbUpdateTS > $fileInputUpdateTS) {
                    rex_file::put($inputFilepath, $input);
                }
            } else {
                rex_file::put($inputFilepath, $output);
            }
            $outputFilepath  = self::getModuleFilepath($name, $dir, 'output');
            if (file_exists($outputFilepath)) {
                $fileOutputUpdateTS = DateTime::createFromFormat('U', filemtime($outputFilepath));
                if ($dbUpdateTS > $fileOutputUpdateTS) {
                    rex_file::put($outputFilepath, $output);
                }
            } else {
                rex_file::put($outputFilepath, $output);
            }
        }
    }


    private static function sanetizeName($input)
    {
        $input = trim(mb_ereg_replace("([^\w\s\d\-_~\[\]\(\).])", '', $input));
        return mb_ereg_replace("([\.]{2,})", '', $input);
    }

    private static function writeTableToFiles(string $target, string $dir)
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM rex_' . $target);
        $count = $sql->getRows();

        rex_logger::logError(E_NOTICE,  'Anzahl Zeilen für ' . $target . ': ' . $count, __FILE__, __LINE__);

        foreach ($sql as $row) {
            $array = $row->getRow(PDO::FETCH_BOTH);
            $arraySer = serialize($array);

            $mId = '' . $array['rex_' . $target . '.id'];


            $fullpath = join(DIRECTORY_SEPARATOR, array($dir, $target, $target . '_' . $mId . '.' . self::$tempFiles));
            rex_file::put($fullpath, $arraySer);

            //var_export($arraySer, true);
        }
    }
}

TemplateModuleSyncService::doSync(self::getName());
