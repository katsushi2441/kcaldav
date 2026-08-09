<?php
echo "パスワードを入力してEnter: ";
$pw = trim(fgets(STDIN));
if ($pw === '') { echo "空です\n"; exit(1); }
echo "kcaldav_config.php の password_hash に貼ってください:\n";
echo password_hash($pw, PASSWORD_DEFAULT) . "\n";
