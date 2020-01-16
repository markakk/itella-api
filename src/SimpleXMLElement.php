<?php

namespace Mijora\Itella;

class SimpleXMLElement extends \SimpleXMLElement
{
  /**
   * Escape values as XML entities
   *
   * @param string
   * @param string
   */
  public function addChild($key, $value = null, $namespace = null)
  {
    if ( $value != null )
    {
      $value = htmlspecialchars($value, ENT_XML1);
    }
    return parent::addChild($key, $value, $namespace);
  }
}