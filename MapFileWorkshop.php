<?php

/**
 * MapFileWorkshop is a PHP library for reading, searching, editing and creating MapFiles for MapServer version 5 to 7 and higher.
 * It's peculiarity is that no regular expression is used. The MapFile is read with respect to
 *  the MapServer syntax, but without understanding the meaning. The dictionary of special keyswords
 *  is stored into a Synopsis class. The Synopsis class can be updated if needed in futur MapServer versions. 
 *
 * The MapFileWorkshop library is also a nice tool to find syntax errors in an existing MapFile.
 *
 * This library is case insensitive for reading a source file.
 * But when using MapFileWorkshop and MapFileObject classes, all MapServer keywords must be specified UPPERCASE.
 *
 * @author  Skrol29
 * @date    2018-11-07
 * @version 0.21-beta-2018-11-07
 * 
 * @example #1
 *
 * $map = new MapFileObject::getFromFile('my_source_file.map'); // read and load the complete MAP definition in memory, i.e. including all layers
 * $layer = $map->getChild('LAYER', 'my_sweet_layer');
 * echo $layer->getProp('DATA'); // reading a property
 * echo $layer->asString(); // get the MapServer definition
 *
 * @example #2
 *
 * $ws = new MapFileWorkshop('my_source_file.map'); // the source file is not sought at this point
 * $layer = $ws->searchObj('MAP/LAYER:my_layer');   // search for a object in the source
 * $class = $layer->getChild('CLASS', 1);
 * $style = $class->getChild('STYLE', 1);
 * $opacity = $style->getProp('OPACITY');
 * $style->setProp('OUTLINECOLOR', $style->colorHex2Ms('FFFFFF'));
 * $style->setComment("modified by the demo");
 * $ws->replaceDef($style->getSrcPosition(), $style); // replace the object in the target file
 *
 *
 * ---------------------
 * class MapFileWorkshop
 * ---------------------
 *   An object for reading/writing/search in an existing MapFile.
 *   It's better to use MapFileObject::getFromFile() for simply getting the root object in a file (usually a MAP object).
 *   Note that the file contents is actually loaded each time you call readFile() or searchObj().
 *
 * Synopsis:
 *   new MapFileWorkshop($sourceFile = false, $targetFile = false) Create a new instance for working on the source file.
 *  ->readFile()               Parse the whole source file and return the root object. It's typically a MAP object.
 *  ->searchObj($str_path)     Parse the source file until the corresponding object is found.
 *  ->readString($txt)         Parse the whole string and return the root object.
 *  ->replaceDef($srcPos, $newDef) Replace a snippet of definition in the source file and save the result in the target file (can be the same file).
 *                             The physical target file is immediately updated.
 *  ->setDebug($debug_level)   Set the debug level for output info during the parsing of the source.
 *  ->sourceFile               The file containing the MapFile source to read.
 *  ->targetFile               The file to save modified source.
 *  ->warnings                 (array) Warnings messages collected during the parsing.
 *
 * -------------------
 * class MapFileObject
 * -------------------
 *   Represents a MapFile object, with its properties and children objects.
 *
 * Synopsis:
 *  new MapFileObject($type) Create a new object of the given type with no properties and no child.
 *  ::getFromString($str)    Return the object from a string source, or false if not supported.
 *  ::getFromFile($file)     Return the object from a file   source, or false if not supported.
 *  ::checkError($str)       Return the error message when parsing the source, or empty string ('') if no error.
 *  ->innerValues            (read/write) List of the inner values in a single row.
 *                             Inner values are attached to the object without any property's name.
 *                             Example : CONFIG, PROJECTION, SYMBOL, METADA,... 
 *  ->children                 (read only) An indexed array of all MapFileObject children.
 *  ->props                  (read only) An associative array or properties => values.
 *  ->setComment($comment)   Set a comment to the current object.
 *  ->setProp($prop, $value, $strDelim=false, $check=false)
 *                           Set a property to the current object.
 *                           A property is a tag with a single value which is not an object (numer, string, MapServer expression, MapServer regexp)
 *                           $value must be set wihtout escaping or string delimited.
 *                           Put $strDelim to true if the value has to be string delimited for this property (such as an URL, or a file name).
 *  ->getProp($prop, $default = false) Get a property of the current object, or $default if missing.
 *  ->countChildren($type)   Return the number of children of the given type.
 *  ->getChild($type, $nameOrIndex = 1, $nameProp = 'NAME') Return the object corresponding to the criterias.
 *  ->getChildren($type)     Return an array of all children object of the given type. 
 *  ->deleteChildrenByType($type)  Delete all child of the given type.
 *  ->addChildren($arr)      Add one child or an array of children to the current object.
 *  ->asString()             Return the MapFile source for this object, including and its children.
 *  ->getSrcPosition()       Return the position of the object in the source where it comes from.
 *  ->saveInFile($file)      Save the object in the file.
 * 
 *  ->escapeString($txt)     Escape a string. Do not use it for setProp()
 *  ::colorHex2Ms()          Convert a '#hhhhhh' color into 'r g b'.   Note that MapServer supports format '#hhhhhh'.
 *  ::colorMs2Hex()          Convert a 'r g b'   color into '#hhhhhh'. Note that MapServer supports format '#hhhhhh'.
 * 
 * ---------------------
 * class MapFileSynopsis
 * ---------------------
 *  It's a core class that contains the dictionary of MapFile special keyswords.
 *  Keywords that are not in the synopsis are supposed to be simple MapFile properties (a keyword with a single value).
 * 
 *
 */


/**
 * A MapFile Object is any block in the MapFile that has a END tag.
 * Other tags in the MapFile are considered as Properties of an object.
 * Most of Objects have properties and child Objects.
 * But some Objects may have inner values, thus no properties nor child objects (PROJECTION, METADATA, ...).
 * Despite reading a MapFile is case insensitive, properties an objects names are all converted uppercase so
 *  they must be managed uppercase.
 */
class MapFileObject {
    
    public $type = '';
    public $supported = false;
    public $warnings = false; // warnings coming from the reader
    
    public $innerValues = array();
    public $innerValCols = 0;
    public $children = array();
    public $props  = array();      // The only prop wich is multi is CONFIG
    public $delimProps  = array(); // Names of the properties to be ouput with string delimitors
    public $parent = false;
    
    public $hasEnd = true;
    
    public $srcPosBeg = false;
    public $srcPosEnd = false;
    public $srcPosBottom = false;
    public $srcLineBeg = false;
    public $srcLineEnd = false;
    
    private $_comment = '';

    /**
     * Return the object corresponding to string.
	 * @param string  $txt   The string to parse.
	 * @param boolean $snippet (optional) true will return a virtual SNIPPET object that can contains several children. False will resturn the first object.
	 * @param integer $debug_level (optionnal) The debug level (default is none)
     * @return {MapFileObject|false}
     */
    public static function getFromString($txt, $snippet = false, $debug_level = MapFileWorkshop::DEBUG_NO) {
        $map = new MapFileWorkshop(false, false);
		$map->setDebug($debug_level);
        return $map->readString($txt, $snippet);
    }

    /**
     * Return the object corresponding to file content.
	 * @param string  $file   The file to parse.
	 * @param boolean $snippet (optional) true will return a virtual SNIPPET object that can contains several children. False will resturn the first object.
	 * @param integer $debug_level (optionnal) The debug level (default is none)
     * @return {MapFileObject|false}
     */
    public static function getFromFile($file, $snippet = false, $debug_level = MapFileWorkshop::DEBUG_NO) {
        $map = new MapFileWorkshop($file, false, $debug_level);
		$map->setDebug($debug_level);
        return $map->readFile($snippet);
    }
    
    /**
     * Return the error message wehn parsing the oecbjt source, or empty string ('') if no error.
     * @param  {string} $sep (optional) The separator in case they are several errors. Default is a line-break.
     * @return {string}
     */
    public static function checkError($txt, $sep = "\n") {
        $map = new MapFileWorkshop(false, false);
        $map->errorAsWarning = true;
        $map->readString($txt, false);
        return implode($sep, $map->warnings);
    }
    
    /**
     * Constructor
     * @param string $type            The type of the object (cas sensitive).
     * @param string $chk_parent_type (optional) Check if $type is valid for the given parent type. Useful for a type that can be either a block or a property, like 'SYMBOL'.
	 *
     * If the type is unkowed in the synopsis, then property « supported » is set to false.
     */
    public function __construct($type, $chk_parent_type = false) {
        
        $this->type = $type;

        if ($syno = MapFileSynopsis::getSyno($type, $chk_parent_type)) {
            $this->innerValCols = $syno['innerValCols'];
            $this->hasEnd  = $syno['hasEnd'];
            $this->supported = true;
        } else {
            $this->supported = false;
        }

        
    }
    
    /**
     * Apply a comment ot the object. This comment is  is displayed only when using ->asString().
     */
    public function setComment($comment) {
        $comment = (string) $comment;
        $comment = str_replace("\r", '', $comment);
        $comment = str_replace("\n", '', $comment);
        $this->_comment = $comment;
    }
    
    /**
     * Save the property.
     * @param  string         @prop      The name of the property (case insensitive).
     * @param  string|false   @value     The value to set. false or null values make the property to be deleted.
     * @param  string|boolean @strDelim  The string delimitor to use, or true to use the default delimitor (")
     * @param  boolean @check            (optional) Set to true if you want the function to return whereas the property was previously defined or not.
     * @return boolean Return true is $check is set to true and the property already existed before.
     */
    public function setProp($prop, $value, $strDelim=false, $check=false) {
        
        $prop = strtoupper($prop);
        if ($check) {
            $check = isset($this->props[$prop]);
        }
        
        if (($value === false) || (is_null($value)) ) {
            unset($this->props[$prop]);
        } else {
            $this->props[$prop] = $value;
        }
        
        // String delimitor
        if ($strDelim === true) {
            $strDelim = '"';
        } elseif ($strDelim === false) {
            $strDelim = '';
        }
        
        if ($strDelim === '') {
            unset($this->delimProps[$prop]);
        } else {
            $this->delimProps[$prop] = $strDelim;
        }
        
        return $check;
        
    }
    
    /**
     * Read the property.
     * @param  string  @prop    The name of the property (case insensitive).
     * @param  mixed   @default The value to return if the property is not set.
     * @return mixed   The existing property's value or the default value.
     */
    public function getProp($prop, $default = false) {
        $prop = strtoupper($prop);
        if (isset($this->props[$prop])) {
            return $this->props[$prop];
        } else {
            return $default;
        }
    }

    /**
     * Escape and delimit a string.
     */
    public function escapeString($str, $strDelim) {
        return str_replace($strDelim, '\\'.$strDelim, $str);
    }
    
    private function _delim_string($str, $strDelim) {
        return $strDelim . $this->escapeString($str, $strDelim) . $strDelim;
    }
    
    /**
     * Count the number of child for a given type.
     * @param  string $type Type of the objet (case insensitive).
     * @return integer
     */
    public function countChildren($type) {
        $type = strtoupper($type);
        $n = 0;
        foreach($this->children as $obj) {
            if ($obj->type == $name) {
                $n++;
            }
        }
        return $n;
    }
    
    /**
     * Find a child object.
     * @param string $type Type of the objet (case insensitive)
     * @param string|integer $nameOrIndex (optional, default is 1) Name of the object (case sensitive) or the index (first is 1) for this type of object.
     * @param string $nameProp (optional, default is 'NAME') Property for searching the name of the object.
     */
    public function getChild($type, $nameOrIndex = 1, $nameProp = 'NAME') {
        $type = strtoupper($type);
        $i = 0;
        $byName = is_string($nameOrIndex);
        foreach($this->children as $obj) {
            if ($obj->type == $type) {
                $i++;
                if ($byName) {
                    if ($obj->getProp($nameProp) == $nameOrIndex) {
                        return $obj;
                    }
                } else {
                    if ($i == $nameOrIndex) {
                        return $obj;
                    }
                }
            }
        }            
        return false;
    }
    
    /**
     * Return the array of children for a given type.
     * @param string       $type The type to child to return (case sentitive).
     * @param false|string $prop (optional) The property to return (case sentitive), or false to return the MapfileObject.
     * @return array
     */
    public function getChildren($type, $return_prop = false) {
        $res = array();
        foreach ($this->children as $obj) {
            if ($obj->type == $type) {
                if ($return_prop) {
                    $res[] = $obj->getProp($return_prop);
                } else {
                    $res[] = $obj;
                }
            }
        }
        return $res;
    }
    
    /**
     * Delete all children of a give type.
     * @param string $type The type to search (case sentitive).
     * @return integer The number of deleted children.
     */
    public function deleteChildrenByType($type) {
        $n = 0;
        for ($i = count($this->children) -1; $i >= 0 ; $i--) {
            $obj = $this->children[$i];
            if ($obj->type == $type) {
                $obj->parent = false;
                array_splice($this->children, $i, 1);
                $n++;
            }
        }
        return $n;
    }
    
    /**
     * Delete all children.
     * @return integer The number of deleted children.
     */
    public function deleteAllChildren() {
        foreach ($this->children as &$c) {
            $c->parent = false;
        }
        $n = count($this->children);
        $this->children = array();
        return $n;
    }
    
    /**
     * Add a new child or an array of children.
     * @param  MapFileObject|array $arr
     * @return integer The number of added children.
     */
    public function addChildren($arr) {
        $n = 0;
        if (is_object($arr)) {
            $arr = array($arr);
        }
        if (is_array($arr)) {
            foreach ($arr as $obj) {
                $obj->parent = $this;
                $this->children[] = $obj;
                $n++;
            }
        }
        return $n;
    }

    /**
     * If the object is the result of a search, then return an array containing the start en end positions
     *  of the object in the source.
     * Otherwise return false.
     */
    public function getSrcPosition() {
        
        if ($this->srcPosBeg !== false) {
            return array($this->srcPosBeg, $this->srcPosEnd);
        } else {
            return false;
        }
        
    }

    /**
     * If the object is the result of a search, then return the bottom position
     *  of the object in the source. That is the position just before the END word.
     * Otherwise return false.
     */
    public function getSrcBottom() {
        
        return $this->srcPosBottom;
        
    }
    
    /**
     * Return the MapServer definition of the object as a string.
     * @param integer $indent (optional) The number of text indentations. 
     */
    public function asString($indent = false) {
        
        static $nl = "\n";
        static $step = '  '; 
        
        if ($indent === false) {
            $indent = 0;
        }
        
        $incr = str_repeat($step, $indent);
        $end = $this->hasEnd;
        
        // Type
        $str = $incr . $this->type;
        
        // Comment
        if ($this->_comment !== '') {
            $str .= $nl . $incr . $step . '# ' . $this->_comment;
        }
        
        // Inner values
        $col = 1;
        foreach ($this->innerValues as $val) {
            // Inner values can be numerical (example: PATTERN)
            if (!is_numeric($val)) {
                $val = $this->_delim_string($val, '"');
            }
            if ( ($col == 1) && $end ) {
                $str .= $nl . $incr . $step;
            } else {
                $str .= ' ';
            }
            $str .= $val;
            $col++;
            if ($col > $this->innerValCols) {
                $col = 1;
            }
        }
        
        $hasKids = (count($this->children) > 0);
        $hasProp = (count($this->props) > 0);
        
        // Properties
        if ($hasProp) {
            if ($hasKids) $str .= $nl;
            foreach ($this->props as $prop => $val) {
                if (isset($this->delimProps[$prop])) {
                    $val = $this->_delim_string($val, $this->delimProps[$prop]);
                }
                $str .= $nl . $incr . $step. $prop . ' ' . $val;
            }
        }
        
        // Child objects
        $children = $this->_orderder_children(); // increase the time output by 30%
        foreach ($children as $obj) {
            // Just like MapScript, we add a line-break before each object of the MAP (level = 1)
            if ($hasKids && $obj->hasEnd) {
                $str .= $nl;
            }
            $str .= $nl . $obj->asString($indent + 1);
        }
        
        // End
        if ($end) {
            if ($hasKids) $str .= $nl;
            $str .= $nl . $incr . 'END # ' . $this->type;
        }
        
        return $str;
    
    }
    
    /**
     * Save the object in the file.
     * return true.
     */
    public function saveInFile($file) {

        $str = $this->asString();

        file_put_contents($file, $str);
        
        return true;
        
    }
    
    /**
     * Return a short descr of the current object.
     */
    private function _short_descr() {
        
        $name = $this->getProp('NAME', '');
        $num = $this->_get_child_num();
        
        $x = $this->type;
        if ($num !== false)  $x .= '(#' . $num . ')';
        if ($name != '')  $x .= '[' . $name . ']';
        
        return $x;

    }

    /**
     * Return the ordre numer of current object in the parent's child of the same type.
     * First child is number 1.
     * Return false if no parent or current object not found.
     */
    private function _get_child_num() {
        
        if ($this->parent) {
            $num = 0;
            foreach ($this->parent->children as $c) {
                if ($this->type == $c->type) {
                    $num++;
                }
                if ($this === $c) {
                    return $num;
                }
            }
        }

        return false;
        
    }
    
    /**
     * Return the list of child ordered by type.
     * More numerous types are ordered at the end, then it is ordered by type name.
     * In a same type, children are nor re-ordered because custom CLASS and STYLE orders actually matter.
     */
    private function _orderder_children() {

        // build the type list
        $t_nb  = array();
        $t_ch = array();
        foreach ($this->children as $idx => $c) {
            $t = $c->type;
            if (!isset($t_nb[$t])) {
                $t_nb[$t] = 0;
                $t_ch[$t] = array();
            }
            $t_nb[$t]++;
            $t_ch[$t][] = $c;
        }
        
        // Sort the type list by number of items
        ksort($t_nb);
        asort($t_nb);
        
        // Cuild the child list sorted by type
        $result = array();
        foreach($t_nb as $t => $nb) {
            foreach ($t_ch[$t] as $c) {
                $result[] = $c;
            }
        }

        return $result;
        
    }
    
    /**
     * Return a breadcrumb of the current object in its parent hierarchy.
     * @return {string}
     */
    public function getBreadcrumb() {
        
        $sep = '/';
        
        $obj = $this;
        $h = array();
        do {
			if ($obj->type != ':SNIPPET:') {
				$h[] = $obj->_short_descr();
			}
            $obj = $obj->parent;
        } while ($obj);
        
        $x = implode($sep, array_reverse($h));
        
        return $x;
        
    }

    /**
     * For debug only.
     * Use this method to return a var_export() on the current object. This will avoid « Fatal error: Nesting level too deep ».
     */
    public function varExport() {
		$this->_unsetParent();
		$x = var_export($this, true);
		$this->_setParent();
		return $x;
    }
    
	private function _unsetParent() {
		$this->parent = false;
		foreach ($this->children as $c) {
			$c->_unsetParent();
		}
	}

	private function _setParent() {
		$this->parent = false;
		foreach ($this->children as $c) {
			$c->_setParent();
		}
	}
    
    /**
     * Convert an hexa color number into a MapServer (RGB) color number.
     * Empty values are returned as is.
     * @param string $hex The color number as hexa, with or without the '#' symbole.
     * @return string The MapServer color number.
     */
    public static function colorHex2Ms($hex) {
        
        // Check empty value
        $hex = trim($hex);
        if ($hex == '') return '';
        
        $hex = str_replace('#', '', $hex);
        
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex,0,1).substr($hex,0,1));
            $g = hexdec(substr($hex,1,1).substr($hex,1,1));
            $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
            $r = hexdec(substr($hex,0,2));
            $g = hexdec(substr($hex,2,2));
            $b = hexdec(substr($hex,4,2));
        }
       
        $rgb = $r . ' ' . $g . ' ' . $b;
        return $rgb;
       
    }    

    /**
     * Convert a MapServer (RGB) color number into an hexa color number.
     * Empty values are returned as is.
     * @param string $rgb The color number as MapServer (RGB separated with with spaces).
     * @return string The Hexa color number, without '#'.
     */
    public static function colorMs2Hex($rgb) {
        
        $rgb = str_replace("\r", ' ', $rgb);
        $rgb = str_replace("\n", ' ', $rgb);
        $rgb = str_replace("\t", ' ', $rgb);
        while (strpos($rgb, '  ') !== false) {
            $rgb = str_replace('  ', ' ', $rgb);
        }
        
        // Check empty value
        $rgb = trim($rgb);
        if ($rgb == '') return '';
        
        // Ensure last items
        $rgb .= ' 0 0 0';
        $rgb = trim($rgb);
        $rgb = explode(' ', $rgb);
        
        $hex = '';
        $hex .= str_pad(dechex($rgb[0]), 2, '0', STR_PAD_LEFT);
        $hex .= str_pad(dechex($rgb[1]), 2, '0', STR_PAD_LEFT);
        $hex .= str_pad(dechex($rgb[2]), 2, '0', STR_PAD_LEFT);

        return $hex;

    }
    
}


/*
 * The MapFileSynopsis class manages the dictionary of special keywords in MapFiles and their corresponding properties needed to read them.
 *
 * For this library, a « simple » MapServer keyword is a keyword that is expected to be followed by a single value (according to the MapFile syntax).
 * For this library, a « special » keyword is any keyword that is not a simple keyword.
 * The dictionary of special keywords and their properties is listed above.
 *
 */
class MapFileSynopsis {
	
	/**
	 * Default properties for all special keywords.
	 */
    private static $_default = array(
		'hasEnd' => true,          // false means the keyword has no END tag. Thus the end of the object is determined using property innerValCols.
		'innerValCols' => 0,       // The number of values expected for this keyword. 0 means the object has no inner value (it contains keywords).
		'onlyForParents' => false, // List of keyworks for whom this one is special. For other parents, this one is considered has a simple keyword (a keyword with a single value).
		'onlyIfNoParent' => false, // True means that this keyword is special only if it has no parent (as root or as a free object).
		'several' => false,        // True means that they may be several of this object type in the same parent.
	);
    
    /**
     * List of special keywords and their properties.
     */
    private static $_special_kw = array(
	  'CLASS' => array( // children: LABEL, LEADER, STYLE, VALIDATION
		'several' => true,
	  ),
	  'CLUSTER' => array(),
	  'COLORRANGE' => array(
		'hasEnd' => false,
		'innerValCols' => 2, // supports only hexadecimal strings for now, (r g b) values not supported yet
		'several' => true,
	  ),
	  'COMPOSITE' => array(),
	  'CONFIG' => array(
		'hasEnd' => false,
		'innerValCols' => 2,
		'several' => true,
	  ),
	  'FEATURE' => array(), // children: POINTS
	  'GRID' => array(),
	  'JOIN' => array(),
	  'LABEL' => array(), // children: STYLE
	  'LAYER' => array( // children: CLUSTER, COMPOSITE, FEATURE, PROCESSING, GRID, JOIN, PROJECTION, VALIDATION, CLASS
		'several' => true,
	  ),
	  'LEADER' => array(), // children: STYLE
	  'LEGEND' => array(), // children: LABEL
	  'MAP' => array(), // children: CONFIG, OUTPUTFORMAT, PROJECTION, LEGEND, QUERYMAP, REFERENCE, SCALEBAR, SYMBOL, WEB, LAYER
	  'METADATA' => array(
		'innerValCols' => 2,
	  ),
	  'OUTPUTFORMAT' => array(),
	  'PATTERN' => array(
		'innerValCols' => 1,
	  ),
	  'POINTS' => array(
		'innerValCols' => 2,
	  ),
	  'PROCESSING' => array(
		'hasEnd' => false,
		'innerValCols' => 1,
		'several' => true,
	  ),
	  'PROJECTION' => array(
		'innerValCols' => 1,
	  ),
	  'QUERYMAP' => array(),
	  'REFERENCE' => array(),
	  'SCALEBAR' => array(), // children: LABEL
	  'STYLE' => array(  // children: PATTERN, COLORRANGE
		'several' => true,
	  ),
	  'SYMBOL' => array( // children: POINTS
		'onlyForParents' => array(
		  'MAP',
		  'SYMBOLSET',
		),
		'several' => true,
	  ),
	  'SYMBOLSET' => array( // children: SYMBOL
		'onlyIfNoParent' => true,
	  ),
	  'VALIDATION' => array(
		'innerValCols' => 2,
	  ),
	  'WEB' => array(), // children: METADATA
	);
    
	// Indicates if the dictionary has to be prepared.
	static $to_prepare = true;

		
    /**
     * Returns the configuration of an object or false if the tag name is not a knowed object (but may be a valid property).
	 *
     * @param string $name             The name of a tag.
     * @param string $chk_parent_type (optional) Check if $type is valid for the given parent type. Useful for a type that can be either a block or a property, like 'SYMBOL'.
	 *
     * @return array|boolean
     */
    static public function getSyno($name, $chk_parent_type = false) {
        if (isset(self::$_special_kw[$name])) {
            $syno = self::$_special_kw[$name];
            // Check if the item is an object only for the given parent type
            if ($chk_parent_type !== false) {
                if ($syno['onlyForParents']) {
                    if (!in_array($chk_parent_type, $syno['onlyForParents'], true)) {
                        return false;
                    }
                } elseif ($syno['onlyIfNoParent']) {
                    if ($chk_parent_type != ':SNIPPET:') {
                        return false;
                    }
                }
            }
            return $syno;
        } else {
            return false;
        }
    }
   
    /**
     * Return true if the tag is the one for ending blocks.
     * @param  string  @name
     * @return boolean
     */
    static public function isEnd($name) {
        return ($name === 'END');
    }
    
    /**
     * Return true if the tag name is valid.
     * @param  string  @name
     * @return boolean
     */
    static public function isValidName($name) {
        if ($name == '') return false;
        // For now we check only the first char. TODO : check MapServer keywords naming.
        $x = strtoupper($name[0]);
        $o = ord($x);
        // ord('A')=65, ord('Z')=90
        if ($o < 65) return false;
        if ($o > 90) return false;
        return true;
    }
    
    /**
     * Prepare the dictionary if it has not been done before.
     * Preparing the dictionary consists in setting all default preperties and check for coherence.
     */
    static public function prepare() {

        if (self::$to_prepare) {
			
            foreach (self::$_special_kw as $k => $def) {
				
				$def = array_merge(self::$_default, $def);
				self::$_special_kw[$k] = $def;
				
				// Check 'hasEnd'
				if (!$def['hasEnd']) {
					if ($def['innerValCols'] <= 0) {
						self::_raiseError("Synopsis ERROR: item '$k' has 'hasEnd' set to false wihtout a positive 'innerValCols'. You have to fix the Synopsis configuration.");
					}
				}
				
			}
			
            self::$to_prepare = false;
			
        }
    }       

    static private function _raiseError($msg) {
        throw new Exception(__CLASS__ . " ERROR : " . $msg);
        return false;
    }    

}

class MapFileWorkshop {
    
    const NL  = "\n";    // New line
    const CR  = "\r";    // Carriage Return
    const SP  = ' ';     // Space
    const TAB = "\t";    // Tab
    const COMMENT = '#'; // Comment
    
    const DEBUG_NO = 0;
    const DEBUG_NORMAL = 1;
    const DEBUG_DETAILED = 2;
    const DEBUG_DEEP = 3;
    
    const STR1 = '"';  // String delimiter  #1
    const STR2 = "'";  // String delimiter  #2
    const ESC  = '\\'; // String escaper

    // Last loaded file
    public $file = false;

    // Warning messages collected during the parsing
    public $warnings = array();
    
    // Variables for couting lines
    private $_line_num = 0; // current line number
    private $_npos = -1;    // « position + 1 » of the last line-break
    private $_fchar = '';   // first char of the last line-break
	private $_continue = false;
	
    // Buffer variables for converting strings to objects
    private $_currObj = false;
    private $_currProp = false;
    private $_currPropSD = ''; // String delimitor for the property
    private $_currValues = array();

	// Contain the hierachy of objects that has not been complety read. They are not yet attached to their parent.
	private $_currPath = false;
	private $_currIdx = false;
    
    private $_srchOk = false;
    private $_srchPath = false;
    private $_srchLastIdx = false;
    private $_srchFound = false;
    
    /**
     * If debug mode is activated then informations is displayed concerning the parsing. 
     */
    public $debug = 0; // must be a constant of the class
    public $debug_n = 0;
    public $debug_max = 40000;
    
    /**
     * Use this property to catch the errors into $this->warnings instead of raising an Exception.
     */
    public $errorAsWarning = false;
    
    /**
     * Constructor
     * @param {string}  $sourceFile  (optional) The source file path to work with. Will not be loaded now.
     * @param {string}  $targetFile  (optional) The target file where to save modifications. By default it is $sourceFile. 
     */
    public function __construct($sourceFile = false, $targetFile = false) {
		
        MapFileSynopsis::prepare();
		
        $this->sourceFile = $sourceFile;
        $this->targetFile = $targetFile;
        if (!$this->targetFile) {
			$this->targetFile = $this->sourceFile;
		}
		
    }
        
    /**
     * Get the root object from the MapFile. (It is usually a MAP object).
	 * @param boolean $snippet (optional) true will return a virtual SNIPPET object that can contains several children. False will resturn the first object.
     */
    public function readFile($snippet = false) {
        $this->_srchOk = false;
        return $this->_read_file($snippet);
    }
    
    /**
     * Search for the first object that matches the path.
     *
	 * @param string $str_path Example : 'MAP/LAYER:my_layer'
	 *                         The target object must be defined with its hierarchy.
	 *                         Each item can be : 
	 *                         - a simple type (examples : 'MAP', 'LAYER', ...)
	 *                         - a type with a child numerical index - first index is 1 - ( examples : 'LAYER:1', 'CLASS:1', ...)  
	 *                         - a type with a name (example : 'LAYER:my_layer')
     */
    public function searchObj($str_path) {
        
		
		$this->_srchPath = $this->_get_search_path($str_path);
		$this->_srchLastIdx = count($this->_srchPath) - 1;
		
		$this->_srchOk = ($this->_srchLastIdx > 1);
		$this->_srchFound = false;
		
		if ($this->_srchOk) {
			$obj = $this->_read_file(false);
		    $this->_srchOk = false;
			return $obj;
		} else {
			return false;
		}
        
    }
    
    /**
     * Replace a part of the source and save the result in the target file (can be the same file).
     *
     * @param array                $srcPos  Array of the two positions of replacement in the source file: array($pos_start, $pos_end) 
     *                                      It is typically given by a MapFileObject using method ->getPosition()
     * @param MapFileObject|string $newDef  The new definition to write in the target file.
     *                                      It can be a string or a MapFileObject.
     * @param boolean              $protect (optional) protect the snippet to insert from beeing mixed with surrounding words or comments. 
     *
     * @return boolean True if the definition is replaced in the file.
     */
    public function replaceDef($srcPos, $newDef, $protect = true) {
        
        // check source file
        if ($this->sourceFile === false) {
            return $this->raiseError("Cannot replace object if no source file is defined.", __METHOD__);
        }

        // check target file
        if ($this->targetFile === false) {
            return $this->raiseError("Cannot replace object if no target file is defined.", __METHOD__);
        }
        
        $txt = file_get_contents($this->sourceFile);

        // Get the new object definition
        if (is_object($newDef)) {
            $str = $newDef->asString();
            $protect = true;
        } else {
            $str = $newDef;
        }
        
        // Positions
        if (is_array($srcPos) && isset($srcPos[0]) && isset($srcPos[1])) {
            $beg = $srcPos[0];
            $end = $srcPos[1];
        } elseif (is_numeric($srcPos)) {
            $beg = $srcPos;
            $end = $srcPos - 1; // in order to have a replace length = 0
        } else {
            return $this->raiseError("First argument is not a position. Array or Integer expected", __METHOD__);
        }
        
        // Protect the snippet to insert ($str) from words or comments in the current source ($txt)
        if ($protect) {
            if (!$this->_is_safe_pos($txt, $beg, -1)) {
                if (!$this->_is_safe_pos($str, 0, +1)) {
                    $str = self::NL . $str;
                }
            }
            if (!$this->_is_safe_pos($txt, $end, +1)) {
                if (!$this->_is_safe_pos($str, strlen($str), -1)) {
                    $str = $str . self::NL;
                }
            }
        }

        // Replace the block
        $txt = substr_replace($txt, $str, $beg, $end - $beg + 1);

        // Save in file
        file_put_contents($this->targetFile, $txt);
        
        return true;
        
    }

    /**
     * Read a structure from a string.
     * @param string  $txt        The string to parse
	 * @param boolean $snippet (optional) true will return a virtual SNIPPET object that can contains several children. False will resturn the first object.
	 *                                       It can be useful to return the snippet object if you know that $txt can contain several objects or property.
	 *                                       Bu usually a snippet contain only object root object.
     * @return MapFileObject The type of the MapFileObject is always ':SNIPPET:'. The MAP object is usually a child of this object.
     */
    public function readString($txt, $snippet = false) {

        // Initialize the line counter
        $this->_line_init();
        
        // Initialise properties
        $this->_read_init();
        
        $pos = 0;
        
        // We add a space at the end to ensure that the last char is passed over.
        $txt .= ' ';
        $pos_stop = strlen($txt);
        
        // Main string
        $word = '';        // Current word
        $expr = false;     // Current expression
        $word_end = false; // Tells if the end of the word or expression is met
        $word_p1 = 0;      // Current string starting position
        $word_p2 = 0;      // Current string ending position
        // Second string read by sub-process (expression, )
        $delim = '';       // Current string delimitor, if any
        $move = false;     // Indicate if the position must be moved
        
        $n = 0;
        $n_max = 200000;
        if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "Start of the source.");

		$this->_continue = ($pos < $pos_stop);
        while ($this->_continue) {
            
            // Read char
            $x = $txt[$pos];
            $move = false;
            
            if ($x === self::COMMENT) {
                // Comments: we move until the end of the comment
                $word_end = true;
                $pos = $this->_next_line_pos($txt, $pos + 1, $pos_stop);
            } elseif ($this->_is_linebreak($pos, $x)) {
                // Line-breaks
                $word_end = true;
                $move = true;
            } elseif ($this->_is_string_delim($x)) {
                // read the delimited string (moves current pos)
                $word_end = true;
                $delim = $x;
                $expr = $this->_read_string($txt, $pos, $pos_stop, $x, false, false);
            } elseif ($this->_is_like_ws($x))  {
                // end of name
                $word_end = true;
                $move = true;
            } elseif ($xe = $this->_expr_end_delim($x)) {
                $word_end = true;
                $expr = $this->_read_expression($txt, $pos, $pos_stop, $x, $xe);
            } else {
                // continue to read the tag's name
                if ($word === '') {
                    $word_p1 = $pos;
                }
                $word_p2 = $pos;
                $word .= $x;
                $move = true;
            }

            // Process the word or expression if any
            if ($word_end) {
                if ( ($word === '') && ($expr === false) ) {
                    // Nothing to read
                } else {
                    // Debug
                    if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, $this->_debug_info_pos($pos) . " store info : word=$word, expr=$expr, delim=($delim)");
                    // Store the word or expression information into the buffer
                    $this->_store_info($word, $expr, $delim, $word_p1, $word_p2, $pos);
                    // Reset strings
                    $word = '';
                    $expr = false;
                    $delim = '';
                }
                $word_end = false;
            } else {
                if ( ($word!=='') && ($expr !== false) ) {
                    return $this->raiseError("Unexpected end of tag '{tag}'.");
                }
            }
            
            // Next tag
            if ($move) {
                $pos++;
            }
			
			if ($pos >= $pos_stop) {
				$this->_continue = false;
			}
            
        }

		// Search result
		if ($this->_srchOk && $this->_srchFound) {
			return $this->_currObj;
		}
        
        if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "End of the source.");

		// Add warning if we are not back to the first item of the path
		if ($this->_currIdx != 0) {
			return $this->raiseError("The end of the source is met with {$this->_currIdx} missing block endings.");
		}
        
		// Object to return
		$result = false;
		if ($this->_srchOk) {
			// not found
		} elseif ($snippet) {
			$result = $this->_currObj;
		} else {
			if (isset($this->_currObj->children[0])) {
				$result = $this->_currObj->children[0];
			}
		}
		
        // Copy warnings
		if ($result !== false) {
			$result->warnings = $this->warnings;
		}
        
        return $result;

    }

    /**
     * Set the debug level.
     * @param {integer} $debug_level 
     */
    public function setDebug($debug_level) {
        $this->debug = $debug_level;
    }
    
    /**
	 * @param boolean $snippet True means the function return the SNIPPET object, fals means its first child (usually a MAP object).
	 */
    private function _read_file($snippet) {
        
        $this->warnings = array();
        $txt = file_get_contents($this->sourceFile);
        $obj = $this->readString($txt, $snippet);
        return $obj;
        
    }
    
    /**
     * Interprets the strings just parsed from the file and stores the result object.
     * @param string        $word   An un-delimited string. Can be: tag name, number, attribute ([xxx]), un-delimited keyword.
     * @param string|false  $expr   A delimited string, or false if there is no expression.
     * @param string  $delim  The string delimitor corresponding to $expr, or '' if $expr is not a string.
     * @param integer $p1 The position of the begining of $word in the file.
     * @param integer $p2 The position of the end      of $word in the file.
     * @param integer $p3 The position of the end      of $expr in the file.
     */
    private function _store_info($word, $expr, $delim, $p1, $p2, $p3) {
                
        // Processes the first string. It's an undelimited string.
        if ($word !== '') {
            
            if ($expr !== false) {
                $this->warnings[] = $this->_debug_info_pos($p1) . " : no space between the item and the expression or the string.";
            }

            if (is_numeric($word) || ($word[0] === '[')) {
                // It's a value
                if ($this->_currProp === false) {
                    //return $this->raiseError("Value '{$word}' is read while not tag name is specified.");
                    $this->_add_inner_value($word, $p2);
                } else {
                    $this->_currValues[] = $word;
                    if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "new value : " . $this->_debugCurrObj());
                }
            } else {
                // It may be a keyword-value or a new item
                $nval = count($this->_currValues);
                if (($this->_currProp === false) || ($nval > 0)) {
                    // If no current prop => It's a tag (object or property)
                    $this->_commit_prop();
                    $name = strtoupper($word);
                    if (MapFileSynopsis::isEnd($name)) {
                        // It's the end of an object
                        if ($this->_currObj) {
                            $this->_commit_obj($p2, $p1);
                        } else {
                            return $this->raiseError("An END tag is found outside the scope of a block.");
                        }
                    } else {
                        $obj = new MapFileObject($name, $this->_currObj->type);
                        // $obj is false if it's a property
                        if ($obj->supported) {
                            // It's an object
                            $obj->srcPosBeg = $p1;
                            $this->_add_empty_obj($obj);
                        } else {
                            // It's a property
                            // (no property to commit)
                            // Check if name is valid
                            if (MapFileSynopsis::isValidName($name)) {
                                $this->_currProp = $name;
                                if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "new property : " . $this->_debugCurrObj());
                            } else {
                                return $this->raiseError("Unvalid property name : '" . $name . "'.");
                            }
                        }
                    }
                } else {
                    $this->_currValues[] = $word;
                    // Since it's a key-word value and such value can only be single, then we commit the property
                    $this->_commit_prop();
                }
            }
            
            
        }
        
        // Processes the expression (may be a string or other MapServer expression)
        if ($expr !== false) {
            
            if ($this->_currProp === false) {
                $this->_add_inner_value($expr, $p3);
            } else {
                $this->_currValues[] = $expr;
                $this->_currPropSD = $delim; // avoid the object conversion
                if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "new delimited value : " . $this->_debugCurrObj());
            }
            
        }
        
    }

    /**
     * Add an inner value to the current object.
     *
     * @param {string} $val    The value to add. It can contains a number.
     * @param {string} $posEnd The value to add. It can contains a number.
     */
    private function _add_inner_value($val, $posEnd) {
        if ($this->_currObj) {
            $obj =& $this->_currObj; 
            if ($obj->innerValCols == 0) {
                return $this->raiseError("Cannot add inner value '{$val}'. Inner values are not allowed for object '{$obj->type}'.");
            } else {
                $obj->innerValues[] = $val;
                if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "new inner value : " . $this->_debugCurrObj());
                if ( (!$obj->hasEnd) && (count($obj->innerValues) >= $obj->innerValCols) ) {
                    if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "close object because of an omitted END after {$this->_currObj->innerValCols} inner values.");
                    $this->_commit_obj($posEnd, $posEnd);
                }
            }
        } else {
            return $this->raiseError("There is a delimited string or expression outside a block.");
        }
    }
    
    /**
     * Initialize the line counter.
     */
    private function _line_init() {
        $this->_line_num = 1;
        $this->_npos = 0;
        $this->_fchar = '';
    }
    
    /**
     * Initilize the buffer variables.
     */
    private function _read_init() {

		$this->_currObj = new MapFileObject(':SNIPPET:');
        $this->_currProp = false;
        $this->_currPropSD = '';
        $this->_currValues = array();

		// The item at index 0 is always the root snippet object.
		$this->_currPath = array(
			0 => array('idx' => 0, 'match' => true, 'obj' => $this->_currObj),
		);
		$this->_currIdx = 0;

    }
   
    /**
     * Commit the buffer property to the buffer object.
     */
    private function _commit_prop() {

        // Commit current property (if any)
        if ($this->_currObj) {
        
            if ($this->_currProp) {

                if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "commit current property : " . $this->_debugCurrObj());

                $val = implode(' ', $this->_currValues);
                $existed = $this->_currObj->setProp($this->_currProp, $val, $this->_currPropSD, true);
                
                if ($existed) {
                    $this->warnings[] = $this->_debug_info_pos(false) . " : property '{$this->_currProp}' was previously defined. This new value overwrites the previous one.";
                }
                
                $this->_currProp = false;
                $this->_currPropSD = '';
                $this->_currValues = array();
                
            }
            
        }
    
    }

    /**
     * Add a new object as the child of the current one, and set the new object as the current object.
     */
    private function _add_empty_obj($obj) {

        $obj->srcLineBeg = $this->_line_num;

        // Set the new object as current one
        $this->_currObj =& $obj;

		$match = $this->_currPath[$this->_currIdx]['match'];
		
		$this->_currIdx++;
		$this->_currPath[$this->_currIdx] = array('idx' => $this->_currIdx, 'match' => $match, 'obj' => $obj);
        
        if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_NORMAL, __METHOD__, "add new object : " . $this->_debugCurrObj());

    }
	
    /**
     * Close the current object and move to the parent one.
     */
    private function _commit_obj($posEnd, $posBottom) {
        
        $obj =& $this->_currObj;
        
        // Save file positions
        $obj->srcLineEnd = $this->_line_num;
        $obj->srcPosEnd = $posEnd;
        $obj->srcPosBottom = $posBottom;

		if ($this->_currIdx == 0) {
			return $this->raiseError("The source contains too much block endings. The first block ending out of the scope is at line {$obj->srcLineEnd}, but the wrong closing can be anywhere before.");
		}
		
		$match = true;
		if ($this->_srchOk) {
			$match = $this->_update_curr_match();
			if ($this->_srchFound) {
				return;
			}
		}
		
		// attach the current object to its parent
		if ($match) {
			$this->_currPath[$this->_currIdx-1]['obj']->addChildren($obj);
		}
		
		// go back to parent item		
		unset($this->_currPath[$this->_currIdx]);
		$this->_currIdx--;
		$this->_currObj =& $this->_currPath[$this->_currIdx]['obj'];
        
    }
    
    /**
     * Update the line count and return TRUE if the current position is a line-break.
     * The current position is not updated.
     *
     * @param integer $pos The position of the current character.
     * @param string  $x   The current character.
     *
     * @return integer TRUE if the current position is a line-break.
     */
    private function _is_linebreak($pos, $x) {

        if ( ($x === self::NL) || ($x === self::CR) ) {
            
            if ( ($pos == $this->_npos) && ($x !== $this->_fchar) ) {
                // Note: a line-break can be one of the following: CR+NL, NL+CR, NL or CR.
                // So if the current char is just after a previous line-break char, and if the char is different from the first char of the line-break. 
                // then it is the same line-break.
            } else {
                $this->_line_num++; 
                $this->_fchar = $x; // first char of the last line-break
            }
            
            $this->_npos = $pos + 1; // « position + 1 » of the last line-break
            
            return true;
        }
        
        return false;
    }

    /**
     * Return the ending expression delimitor corresponding to an opening character.
     * Return false if it is not an opening character.
     * @param string $x The character to check.
     * @return string|boolean;
     */
    private function _expr_end_delim($x) {
        if ($x === '(') return ')';
        if ($x === '{') return '}';
        if ($x === '/') return '/';
        return false;
    }

    /**
     * Return true if the char is a string delimitor.
     */
    private function _is_string_delim($x) {
        return ( ($x === self::STR1) || ($x === self::STR2) );
    }
    
    /**
     * Return true if the char is like a white space
     */
    private function _is_like_ws($x) {
        return ( ($x === self::SP) || ($x === self::TAB) );
    }
    
    /**
     * Return the position of the next line.
     * 
     * @param string  $txt       The string to read.
     * @param integer $pos       The current position.
     * @param integer $pos_stop  The end of $txt, that is first position to not read. 
     *
     * @return integer The position to read on the next line.
     */
    private function _next_line_pos($txt, $pos, $pos_stop) {
        
        $end_of_comment = false;
        $n = 0;
        
        while ($pos < $pos_stop) {
            
            $x = $txt[$pos];

            if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_DEEP, __METHOD__, "pos=$pos");
            
            if ($this->_is_linebreak($pos, $x)) {
                $end_of_comment = true;
            } elseif ($end_of_comment) {
                if ($x === self::COMMENT) {
                    // Optimisation : a comment after a line-break makes the process to continue
                    $end_of_comment = false;
                } elseif ($this->_is_like_ws($x)) {
                    // Optimisation : spaces and tabs after the line break are ignored
                } else {
                    // At least one line break has been met, and it's a new char => this is the end
                    return $pos;
                }
            }
            $pos++;
        }       
        
        // At this point we met $pos_stop.
        // No new line found
        return $pos_stop;
        
    }
    
    /**
     * Returne true if the char is a line break
     */
    private function _is_char_lb($x) {
        return ($x === self::NL) || ($x === self::CR);
    }
    
    /**
     * Return true if the position in the text is safe for inserting a snippet before ($step = -1) or after ($step = +1).
     * 
     * @param string  $txt       string to scan
     * @param integer $pos       starting position
     * @param integer $step      +1 or -1
     *
     * @return boolean
     */
    private function _is_safe_pos($txt, $pos, $step) {
        
        $pos = $pos + $step;
        $pos_stop = strlen($txt);
        
        while ( (0 <= $pos) && ($pos < $pos_stop) ) {
            
            $x = $txt[$pos];
            
            if ( $this->_is_char_lb($x) ) {
                return true;
            } elseif ($this->_is_like_ws($x)) {
                // white-spaces and tabs are pending
            } else {
                // any other characters are not accpeted
                return false;
            }
            
            $pos = $pos + $step;
            
        }
        
        // At this point we met the end of the text.
        return true;
        
    }
    
    /**
     * Read an expression which is a string delimited with a character.
     * Moves to position upon the next char after the string.
     * 
     * @param string  $txt       The string to read.
     * @param integer $pos       The current position. Must be the position of the first delimitor.
     *                           After the function call, it becomes the first position after the last delimitor (or the last char of line-break).
     * @param integer $pos_stop  The end of $txt, that is first position to not read. 
     * @param string  $delim     The delimitor character.
     * @param boolean $stop_lb   Stop if a line-break is met (needed for strings nested in expressions).
     * @param boolean $ret_src   Return the string source instead of the string value (needed for strings nested in expressions).
     *
     * @return string|boolean The string source, the string value or true if a line-break is catched.
     *                        Note that $pos is moved during the reading.
     */
    private function _read_string($txt, &$pos, $pos_stop, $delim, $stop_lb, $ret_src) {
        
        $pos_save = $pos;
        $pos++; // Move to first char inside the string.
        
        $str = '';
        $escaped = false;
        $n = 0;
        
        while ($pos < $pos_stop) {
            
            $x = $txt[$pos];
            
            if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_DEEP, __METHOD__, "pos=$pos, chr=" . var_export($x,true) . ", delim=$delim, escaped=" . var_export($escaped,true));

            if ($x === self::ESC) {
                // Escape character : the  next char must be kept.
                $pos++;
                $escaped = true;
            } elseif ( ($x === $delim) && ($escaped === false) ) {
                // End of the string
                $pos++;
                if ($ret_src) {
                    // return the string source
                    return substr($txt, $pos_save, $pos - $pos_save); // $pos as been incremented
                } else {
                    // return the string value
                    return $str;
                }
            } else {
                if ($escaped) {
                    // escape char + not-delim char => both are kept
                    // in other words : escape char does escape any delimt chars
                    if (!$this->_is_string_delim($x)) {
                        $str .= self::ESC;
                    }
                    $escaped = false;
                }
                $str .= $x;
                // Count lines if it is a new line
                $lb = $this->_is_linebreak($pos, $x);
                if ($lb && $stop_lb) {
                    return true;
                } else {
                    $pos++;
                }
            }
        }
        
        // At this point we met $pos_stop.
        return $this->raiseError("Ending string delimitor ({$delim}) is not found.");
        
    }

    /**
     * Read an expression which may contains sub-expressions string expressions.
     * Moves to position upon the next char after the string.
     *
     * @param string  $txt         The string to read.
     * @param integer $pos         The current position.
     *                             After the function call, it becomes the first position after the expression (or the last char of line-break).
     * @param integer $pos_stop    The end of $txt, that is first position to not read. 
     * @param string  $delim_start The staring delimitor character.
     * @param string  $delim_end   The endind  delimitor character.
     *
     * Observed MapServer behavior when parsing expressions:
     * - The parser allows invalid expression such as "(x))", they evaluation always return false.
     * - Line-breaks act as the end of the expression, even string escaped.
     * - A missing closing delimitor produce a MapServer error, while a missing opening delimitor does not. 
     */
    private function _read_expression($txt, &$pos, $pos_stop, $delim_start, $delim_end) {
        
        $scope = 1;
        $pos_save = $pos;
        $pos++;
        $pos_scope_0 = false; // position where the scope is down to 0: that's where the expression should stop
        $end = false;
        $n = 0;
        
        while ($pos < $pos_stop) {
            
            $x = $txt[$pos];

            if ($this->debug !== 0) $this->_debugInfo(self::DEBUG_DETAILED, __METHOD__, "line={$this->_line_num}, pos=$pos, char=" . var_export($x,true) . ", scope = $scope");

            $incr = true;
            
            if ($x === $delim_end) {
                // Check delim_end first because in case it is equal to delim_start, then the seconde delim is a ending delim.
                $scope--;
                if ($scope == 0) $pos_scope_0 = $pos;
                $pos++;
            } elseif ($x === $delim_start) {
                $scope++;
                $pos++;
            } elseif ($this->_is_string_delim($x)) {
                // A delimited string may contains delimitors. So they are to be considered as escaped.
                $str = $this->_read_string($txt, $pos, $pos_stop, $x, true, true);
                // At this point, the position is the first after the last delimitor
                if ($str === true) { // is line-break
                    $end = true;
                    // MapServer seems to allows un-closed string since the string contains the delimitors
                    $this->warnings[] = $this->_debug_info_pos($pos) . " : the MapServer expression contains an un-closed string.";
                    if ($scope > 0) $scope = 0;
                }
            } elseif($this->_is_linebreak($pos, $x)) {
                $end = true;
            } else {
                $pos++;                
            }
            
            if ($scope == 0) $end = true;
            
            if ($end) {
                // End of the expression is met while the scope is not closed
                if ($scope > 0) {
                    return $this->raiseError("Invalid expression : missing delimitor '{$delim_end}'.");
                }
                if ($pos_scope_0 !== false) {
                    /* We have a problem here: (no solution fow now)
                       MapServer allows extra string at the end of the scope of the expression depending the reste of the line.
                       The behavior is something like this :
                       If there is an extra ')' in the rest of the line, MS supposed that the end of the expression
                       is the last ')' on the same line. If this expression has an invalid scope, then ther is no error but return
                       always fast.
                       example : 
                       ('1' = '1') ) # no error, returns false
                    */
                }
                return trim(substr($txt, $pos_save, $pos - $pos_save +1), "\r\n");
            }
            
        }
        
        // At this point we met $pos_stop.
        return $this->raiseError("Ending expression delimitor '{$delim_end}' is found.");
        
    }

	/**
	 * Convert a string path into a internal path format.
     * That is an array with items as array('type'=>..., 'ref'=>..., 'isnum'=>...)
	 */
    private function _get_search_path($str_path) {
		
		$srchPath = array(
			array('type' => ':SNIPPET:', 'ref' => 1),
		);
		
        if (is_string($str_path)) {
			$items = explode('/', $str_path);
		    foreach ($items as $x) {
			    $x = explode(':', $x);
			    if (isset($x[1])) {
					$srchPath[] = array('type'=> $x[0], 'ref' => $x[1]);
			    } else {
					$srchPath[] = array('type'=> $x[0], 'ref' => 1);
			    }
		    }
        } else {
		    foreach ($str_path as $k => $v) {
			    if (is_numeric($k)) {
					$srchPath[] = array('type'=> $v, 'ref' => 1);
			    } else {
					$srchPath[] = array('type'=> $k, 'ref' => $v);
			    }
		    }
        }
		
		foreach ($srchPath as &$rec) {
			$rec['isnum'] = is_numeric($rec['ref']);
		}
		
		return $srchPath;
		
	}
	
	private function _update_curr_match() {
		
		$match = $this->_currPath[$this->_currIdx]['match'];
		
		if ($match) {
			if ($this->_currIdx <= $this->_srchLastIdx) {
				// check the matching
				$chk = $this->_srchPath[$this->_currIdx];
				if ($chk['type'] == $this->_currObj->type) {
					if ($chk['isnum']) {
						$parent = $this->_currPath[$this->_currIdx-1]['obj'];
						$match = ($chk['ref'] == 1 + $parent->countChildren($chk['type']));
					} else {
						$match = ($chk['ref'] == $this->_currObj->getProp('NAME'));
					}
					if ($match && ($this->_currIdx == $this->_srchLastIdx)) {
						$this->_continue = false;
						$this->_srchFound = true;
					}
				} else {
					$match = false;
				}
				if (!$match) {
					$this->_currPath[$this->_currIdx]['match'] = false;
				}
			} else {
				// as the parent
			}
		}
		
		return $match;
		
	}
	
    /**
     * Return pos info as string.
     */
    private function _debug_info_pos($pos) {
        if ($pos === false) {
            return "(line=" . $this->_line_num .")";
        } else {
            return "(line=" . $this->_line_num .", pos=" . ($pos - $this->_npos + 1) . ")";
        }
    }

   
    /**
     * Raise an error as a warning or a custom Exception depending to property $this->errorAsWarning.
     * @param {string}       $msg    The error message.
     * @param {string|false} $source The method of the class the raise the error. 
     */
    public function raiseError($msg, $source = false) {

		$this->_continue = false;

        $info = array();
        if ($source) {
            
            $info[] = "method {$source}";
            
        } else {
            
            if ($this->_line_num !== false) {
                $info[] = "at line {$this->_line_num}";
            }
            
            if ($this->sourceFile !== false) {
                $info[] = "in file {$this->sourceFile}";
            } else {
                $info[] = "in the source string";
            }
            
            $info[] = "for object " . $this->_debugCurrPath();
            
        }
        
        $info = implode(", ", $info);
        if ($info != '') {
            $msg .= '(' . $info .')';
        }
        
        if ($this->errorAsWarning) {
            $this->warnings[] = $msg;
        } else {
            throw new Exception(__CLASS__ . " ERROR : " . $msg);
        }
        
        return false;
    }
    
    /**
     * Display a debug information.
     * @param {integer} $debug_level  Level of debug needed to display this message.
     * @param {string}  $caller The function calling the debug.
     * @param {string}  $msg    The message to display.
     */
    private function _debugInfo($debug_level, $caller, $msg) {
        if ($this->debug >= $debug_level) {
            if ($this->debug_n == 0) echo "<br>\n";
            echo "[{$caller}] " . $msg . "<br>\n";
            $this->debug_n++;
            if ($this->debug_n > $this->debug_max) {
                exit("<br>DEBUG : stop function {$caller} because of too much loop (max is {$this->debug_max}).");
            }
        }
    }
    
    /**
     * Return information about the current object.
     * @return {string}
     */
    public function _debugCurrObj() {
        return "currObj=".(($this->_currObj) ? $this->_currObj->type : 'none').", currProp={$this->_currProp}, currDelim=({$this->_currPropSD}), currVal=" . implode(' ', $this->_currValues);
    }
	
	public function _debugCurrPath() {
		
		$txt = '';
		foreach ($this->_currPath as $item) {
			$txt .= '/' . $item['obj']->getBreadcrumb();
			if ($item['obj']->parent !== false) {
				$txt .= "(with unexpected parent)";
			}
		}
		
		return $txt;
		
	}
    
}
