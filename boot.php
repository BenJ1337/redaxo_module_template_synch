<?php

namespace redaxo_module_template_synch;

use rex_logger, rex_path, rex_sql, rex_file, rex_extension, rex_extension_point, PDO, DateTime;

class TemplateModuleSyncService
{
    private static $ext = 'php';
    private static $tempFiles = 'arr';

    /**
     * Synchronizes between the templates and modules in the database and file on the filesystem. The newer version wins.
     * @param string $name Name des Addons
     * @return void
     */
    public static function doSync(string $name)
    {
        if (true) {
            $path2DataDir = rex_path::addonData($name);
            // Read data from database
            self::writeTableToFiles('module', $path2DataDir);
            self::writeTableToFiles('template', $path2DataDir);
            // Read data previously read from database (and written to disk) into a map structure
            $templates = self::getTemplatesFromFile($path2DataDir);
            $modules = self::getModulesFromFile($path2DataDir);
            // Updates version in workingdirectory (work) if the database version is newer
            self::extractInputOutput($path2DataDir, $modules);
            self::extractContent($path2DataDir, $templates);
            // Updates version in database if the file version of the workingdirectory (work) is newer
            self::syncTemplatesInDb($path2DataDir, $templates);
            self::syncModulesInDb($path2DataDir, $modules);
        }
    }

    /**
     * Updates the version in the database for all templates depending on its timestamp. If the version in the database is older than the file version, it gets updated
     *
     * @param string $path2DataDir Path the this addons data directory
     * @param array $templates Map<Name of template, Content of template>
     * @return void
     */
    private static function syncTemplatesInDb(string $path2DataDir, array $templates): void
    {
        foreach ($templates as $name => $template) {
            $outputFilepath = self::getTemplateContentFilepath($name, $path2DataDir);
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

    /**
     * Updates the version in the database for all modules depending on its timestamp. If the version in the database is older than the file version, it gets updated
     *
     * @param string $path2DataDir Path the this addons data directory
     * @param array $modules Map<Name of module, <type [input|output], Content of type of module>>
     * @return void
     */
    private static function syncModulesInDb(string $path2DataDir, array $modules): void
    {
        foreach ($modules as $name => $module) {
            $inputPath = self::getModuleFilepath($name, $path2DataDir, 'input');
            $outputPath = self::getModuleFilepath($name, $path2DataDir, 'output');
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

    /**
     * Reads the data of the current database version of all templates
     *
     * @param string $path2DataDir Path the this addons data directory
     * @return array Map<Name of template, Content of template>
     */
    private static function getTemplatesFromFile(string $path2DataDir): array
    {
        $rex = join(DIRECTORY_SEPARATOR, array($path2DataDir . 'template', "*." . self::$tempFiles));
        $files = glob($rex);
        $templates = array();
        foreach ($files as $text) {
            $content = unserialize(rex_file::get($text, ''));
            $name = $content['rex_template.name'];
            $templates[self::sanetizeName($name)] = $content;
        }
        return $templates;
    }

    /**
     * Reads the data of the current database version of all modules
     *
     * @param string $path2DataDir Path the this addons data directory
     * @return array Map<Name of module, <type [input|output], Content of type of module>>
     */
    private static function getModulesFromFile(string $path2DataDir): array
    {
        $modules = array();
        $rex = join(DIRECTORY_SEPARATOR, array($path2DataDir . 'module', "*." . self::$tempFiles));
        $files = glob($rex);
        foreach ($files as $text) {
            $content = unserialize(rex_file::get($text, ''));
            $name = $content['rex_module.name'];
            $modules[self::sanetizeName($name)] = $content;
        }
        return $modules;
    }

    /**
     * Updates version in all template files which are older than the version in the database
     *
     * @param string $path2DataDir Path the this addons data directory
     * @param array $templates Map<Name of template, Content of template>
     * @return void
     */
    private static function extractContent(string $path2DataDir, array $templates): void
    {
        foreach ($templates as $name => $template) {
            $templateContent = $template['rex_template.content'];

            $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $template['rex_template.updatedate']);
            $outputFilepath = self::getTemplateContentFilepath($name, $path2DataDir);
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

    /**
     * Extracts data for the specific template by its name and return the content of the file
     *
     * @param string $name Name of the template
     * @param string $path2DataDir Path the this addons data directory
     * @return string
     */
    private static function getTemplateContentFilepath(string $name, string $path2DataDir): string
    {
        $name = self::sanetizeName($name);
        $workpath2Data = join(DIRECTORY_SEPARATOR, array($path2DataDir . 'work', 'template', $name));
        return  join(DIRECTORY_SEPARATOR, array($workpath2Data, 'content.' . self::$ext));
    }

    /**
     * Extracts data for the specific module by its name and return the content of the file of the selected type (input/output)
     *
     * @param string $name Name of the module
     * @param string $path2DataDir Path the this addons data directory
     * @param string $type input or output 
     * @return string
     */
    private static function getModuleFilepath(string $name, string $path2DataDir, string $type): string
    {
        $name = self::sanetizeName($name);
        $workpath2Data = join(DIRECTORY_SEPARATOR, array($path2DataDir . 'work', 'module', $name));
        return join(DIRECTORY_SEPARATOR, array($workpath2Data, $type . '.' . self::$ext));
    }

    /**
     * Updates the version in the files for input and output if the verion of the database is newer than the file version
     *
     * @param string $path2DataDir Path the this addons data directory
     * @param array $modules Map<Name of module, <type [input|output], Content of type of module>>
     * @return void
     */
    private static function extractInputOutput(string $path2DataDir, array $modules): void
    {
        foreach ($modules as $name => $module) {
            $input = $module['rex_module.input'];
            $output = $module['rex_module.output'];

            $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $module['rex_module.updatedate']);

            $inputFilepath  = self::getModuleFilepath($name, $path2DataDir, 'input');
            if (file_exists($inputFilepath)) {
                $fileInputUpdateTS = DateTime::createFromFormat('U', filemtime($inputFilepath));
                if ($dbUpdateTS > $fileInputUpdateTS) {
                    rex_file::put($inputFilepath, $input);
                }
            } else {
                rex_file::put($inputFilepath, $output);
            }
            $outputFilepath  = self::getModuleFilepath($name, $path2DataDir, 'output');
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


    /**
     * converts the string to a valid filename
     *
     * @param string $input name of the template or module
     * @return string valid filename
     */
    private static function sanetizeName(string $input): string
    {
        $input = trim(mb_ereg_replace("([^\w\s\d\-_~\[\]\(\).])", '', $input));
        return mb_ereg_replace("([\.]{2,})", '', $input);
    }

    /**
     * Writes each entry in the table to a separate file
     *
     * @param string $target Database table
     * @param string $path2DataDir Directory to put the files in
     * @return void
     */
    private static function writeTableToFiles(string $target, string $path2DataDir): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM rex_' . $target);
        $count = $sql->getRows();

        rex_logger::logError(E_NOTICE,  'Anzahl Zeilen für ' . $target . ': ' . $count, __FILE__, __LINE__);

        foreach ($sql as $row) {
            $array = $row->getRow(PDO::FETCH_BOTH);
            $arraySer = serialize($array);

            $mId = '' . $array['rex_' . $target . '.id'];


            $fullpath = join(DIRECTORY_SEPARATOR, array($path2DataDir, $target, $target . '_' . $mId . '.' . self::$tempFiles));
            rex_file::put($fullpath, $arraySer);

            //var_export($arraySer, true);
        }
    }
}

rex_extension::register('RESPONSE_SHUTDOWN', function (rex_extension_point $rex_extension_point) {
    TemplateModuleSyncService::doSync(self::getName());
});
