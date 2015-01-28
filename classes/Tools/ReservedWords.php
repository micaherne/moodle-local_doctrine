<?php

namespace local_doctrine\Tools;

class ReservedWords {

    public static $keywords = array('abstract', 'and', 'array', 'as', 'break', 'case', 'callable', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'do', 'die', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends',
        'final', 'finally', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'insteadof', 'interface', 'isset', 'instanceof', 'list', 'namespace', 'new', 'or', 'parent', 'print', 'private',
        'protected', 'public', 'require', 'require_once', 'return', 'self', 'static', 'switch', 'throw', 'trait',
        'try', 'unset', 'use', 'var', 'while', 'xor', 'yield');

    public static function isReserved($word) {
        return in_array($word, self::$keywords);
    }

}