<?php
$text = '';

if (false) {
    $dir = rex_path::addonData($this->getName());
    writeTableToFiles('module', $dir);
    writeTableToFiles('template', $dir);

    $templates = getTemplatesFromFile($dir);
    $moduels = getModulesFromFile($dir);

    extractInputOutput($dir, $moduels);
    extractContent($dir, $templates);

    syncTemplate($dir, $templates);
    syncModules($dir, $moduels);
}


function syncTemplate($dir, $templates)
{
    foreach ($templates as $name => $template) {
        $outputFilepath = getTemplateContentFilepath($name, $dir);
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


function syncModules($dir, $modules)
{
    foreach ($modules as $name => $module) {
        $inputPath = getModuleFilepath($name, $dir, 'input');
        $outputPath = getModuleFilepath($name, $dir, 'output');
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



function getTemplatesFromFile($dir)
{
    $rex = join(DIRECTORY_SEPARATOR, array($dir . 'template', "*.txt"));
    $files = glob($rex);
    $templates = array();
    foreach ($files as $text) {
        $content = unserialize(rex_file::get($text, ''));
        $name = $content['rex_template.name'];
        $name = sanetizeName($name);
        $templates[$name] = $content;
    }
    return $templates;
}

function getModulesFromFile($dir)
{
    $modules = array();
    $rex = join(DIRECTORY_SEPARATOR, array($dir . 'module', "*.txt"));
    $files = glob($rex);
    foreach ($files as $text) {
        $content = unserialize(rex_file::get($text, ''));
        $name = $content['rex_module.name'];
        $modules[$name] = $content;
    }
    return $modules;
}

function extractContent($dir, $templates)
{
    foreach ($templates as $name => $template) {
        $templateContent = $template['rex_template.content'];

        $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $template['rex_template.updatedate']);
        $outputFilepath = getTemplateContentFilepath($name, $dir);
        $fileTemplateUpdateTS = DateTime::createFromFormat('U', filemtime($outputFilepath));

        if ($dbUpdateTS > $fileTemplateUpdateTS) {
            rex_file::put($outputFilepath, $templateContent);
        }
    }
}

function getTemplateContentFilepath($name, $dir)
{
    $name = sanetizeName($name);
    $workDir = join(DIRECTORY_SEPARATOR, array($dir . 'work', 'template', $name));
    return  join(DIRECTORY_SEPARATOR, array($workDir, 'content.txt'));
}

function getModuleFilepath($name, $dir, $art)
{
    $name = sanetizeName($name);
    $workDir = join(DIRECTORY_SEPARATOR, array($dir . 'work', 'module', $name));
    return join(DIRECTORY_SEPARATOR, array($workDir, $art . '.txt'));
}

function extractInputOutput($dir, $modules)
{
    foreach ($modules as $name => $module) {

        $input = $module['rex_module.input'];
        $output = $module['rex_module.output'];

        $dbUpdateTS = DateTime::createFromFormat('Y-m-d H:i:s', $module['rex_module.updatedate']);

        $inputFilepath  = getModuleFilepath($name, $dir, 'input');
        $fileInputUpdateTS = DateTime::createFromFormat('U', filemtime($inputFilepath));
        if ($dbUpdateTS > $fileInputUpdateTS) {
            rex_file::put($inputFilepath, $input);
        }

        $outputFilepath  = getModuleFilepath($name, $dir, 'output');
        $fileOutputUpdateTS = DateTime::createFromFormat('U', filemtime($outputFilepath));
        if ($dbUpdateTS > $fileOutputUpdateTS) {
            rex_file::put($outputFilepath, $output);
        }
    }
}


function sanetizeName($input)
{
    $input = trim(mb_ereg_replace("([^\w\s\d\-_~\[\]\(\).])", '', $input));
    return mb_ereg_replace("([\.]{2,})", '', $input);
}

// rex_logger::logError(E_NOTICE,  $text, __file__, 2);
// rex_logger::logError(E_NOTICE,  $fullpath, __file__, 2);

function writeTableToFiles($target, $dir)
{
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT * FROM rex_' . $target);
    $count = $sql->getRows();

    rex_logger::logError(E_NOTICE,  'Anzahl Zeilen für ' . $target . ': ' . $count, __file__, 16);

    foreach ($sql as $row) {
        $array = $row->getRow(PDO::FETCH_BOTH);
        $arraySer = serialize($array);

        $mId = '' . $array['rex_' . $target . '.id'];


        $fullpath = join(DIRECTORY_SEPARATOR, array($dir, $target, $target . '_' . $mId . '.txt'));
        rex_file::put($fullpath, $arraySer);

        //var_export($arraySer, true);
    }
}
