<?php
require_once 'scripts/common.php';
$config = get_config();
$template = file_get_contents("./scripts/email_template");

foreach($config as $key => $value)
{
    $template = str_replace('{{ '.$key.' }}', $value, $template);
}
echo $template;
?>

