<?php

function NameParsedArray( $a, $b, $c )
{
  return array("dir" => $a, "class" => $b, "method" => $c);
}

function ParseLazy( $str )
{
  $res = explode("/", $str);
  if (end($res) == '')
    array_pop($res);
  if (count($res) <= 3)
  {
    if (count($res) == 1)
      return NameParsedArray("", $res[0], false);
    if (count($res) == 2)
      return NameParsedArray("", $res[0], $res[1]);
    return NameParsedArray($res[0], $res[1], $res[2]);
  }
  $func = array_pop($res);
  $class = array_pop($res);
  return NameParsedArray(implode("/", $res), $class, $func);
}

function ParseGreedy( $str )
{
  $res = ParseLazy($str);
  if (!$res['method'])
  {
    $res['method'] = 'Reserve';
    return $res;
  }
  return NameParsedArray(implode("/", array($res["dir"], $res["class"])), $res["method"], "Reserve");
}

function ParamWalker($str, $begin, $length)
{
  $escape_mode = false;
  $string_mode = false;

  $i = $begin;
  while ($i++ < $length)
  {
    if ($escape_mode)
    {
      $escape_mode = false;
      continue;
    }

    if ($str[$i] == '"')
      $string_mode = !$string_mode;
    else if ($str[$i] == '/')
      $escape_mode = true;
    else if ($string_mode)
      continue;
    else if ($str[$i] == ')')
      break;
  }

  return $i - 1;
}

function TryExtractParams( $str, $support_array = false)
{
  $length = strlen($str);
  $i = -1;

  while (++$i < $length)
    if ($str[$i] == '(')
      break; // if we found arguments begin
    else if ($support_array && $str[$i] == '[')
      break; // or array begin, if it recusion
  if ($i >= $length)
    return null;

  $began = $i + 1;
  $end = ParamWalker($str, $began, $length);
  $args = [];

  if ($end != $began)
  {
    $raw_args_str = substr($str, $began, $end - $began - 1);
    $args_str = preg_replace('/\|(.)/', '$1', $raw_args_str);
    $args = json_decode("[$args_str]", true);
    if (is_null($args))
      die("JSON decode failure");
  }

  if ($str[$end - 1] != ')')
    return null;

  $ret = 
  [
    "module" => substr($str, 0, $began - 1),
    "arguments" => $args,
    "ending" => substr($str, $end),
  ];

  return $ret;
}

function GetRpcObject( $str, $get )
{
  $args = TryExtractParams($str);
  if ($args != null)
  {
    $str = $args['module'];
    $get = $args['arguments'];
  }

  $greedy = ParseGreedy($str);
  $lazy = ParseLazy($str);
  
  if (!$lazy['method'])
    $lazy['method'] = 'Reserve';
  $try = array($greedy, $lazy);

  include_once('include.php');

  foreach ($try as $t)
  {
    if (!$t['class'] || !$t['method'])
      continue;

    if ($t['class'] == 'phoxy') // reserved module name
      $target_dir = realpath(dirname(__FILE__));
    else
      $target_dir = phoxy_conf()["api_dir"];
    
    $obj = IncludeModule($target_dir.'/'.$t["dir"], $t["class"]);
    if (!is_null($obj))
      return
      [
        "original_str" => $str,
        "obj" => $obj,
        "method" => $t["method"],
        "args" => $get,
      ];
  }
  exit(json_encode(["error" => 'Module not found']));
}
