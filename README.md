# MapFile

A PHP library for reading and editing MapFiles for MapServer version 5 to 7 and higher.
It's peculiarity is that no regular expression is used. The MapFile is read with respect to
the MapServer syntax, but without understanding the meaning. The dictionary of special keyswords
is stored into a Synopsis class. The Synopsis class can be updated if needed in futur MapServer versions. 

This library can read MapFile sources with CASE INSENSITIVE keyworks, but all objects of this library will be modelised with UPPERCASE keywords.
So you have to use UPPERCASE keywords for managing objects, while your MapFile can stay case insensitive.

@example #1
$map = new MapFileObject::getFromFile('/opt/my_app/file.map');
echo $map->asString();

@example #2
$mf = new MapFileWorkshop('/opt/my_app/file.map');
if ($layer = $mf->searchObj('LAYER', 'my_country')) {
   if ($class = $layer->getChild('CLASS', 1)) {
      if ($style = $class->getChild('STYLE', 1)) {
         $x = $style->getProp('OPACITY');
         $style->setProp('OUTLINECOLOR', $style->colorHex2Ms('FFFFFF'));
         $mf->replaceDef($style, $style);
      }
   }
}
--------
Classes:
--------
MapFileObject:   Represents a MapFile object (MAP, LAYER, CLASS, STYLE, ....) for reading or editing.
MapFileWorkshop: A toolbox for special search and edition into a physical MapFile.
MapFileSynopsis: A core class for managing the dictionary of special keywords.

--------------
MapFileWorkshop class:
--------------
  An object for reading/writing/search in an existing MapFile.
  It's better to use MapFileObject::getFromFile() for simply getting the root object in the file (usually a MAP object).
  Note that the file is actually loaded each time you call getRootObj() or searchObj().
  
Synopsis:
  new MapFileWorkshop($sourceFile = false, $targetFile = false) Create a new instance for working on the source file.
 ->getRootObj()             Return the root object in the file, it's typically a MAP object.
 ->searchObj($type, $name)  Search for the first object in the root chidren that matches the type and name.
 ->searchObj(array($type_level_1=>$name1, $type_level_2=>$name2, ...))) Search for the first object that matches the path from the root object.
 ->readSource($txt)         Parse the given source and returns the root object.
 ->replaceDef($srcPos, $newDef, $targetFile = false) Replace a snippet of definition in the target file.
                            The physical target file is immediately updated.
 ->setDebug($level)         Set the debug level for output info during the parsing of the source.
 ->sourceFile               The file containing the MapFile source to read.
 ->targetFile               The file to save modified source.
 ->warnings                 (array) Warnings messages collected during the parsing.

--------------
MapFileObject class:
--------------
  Represents a MapFile object, with its properties and children objects.
  
Synopsis:
 new MapFileObject($type) Create a new object of the given type with no properties and no child.
 ::getFromString($str)    Return the object from a string source, or false if not supported.
 ::getFromFile($file)     Return the object from a file   source, or false if not supported.
 ::checkError($str)       Return the error message when parsing the source, or empty string ('') if no error.
 ->innerValues            (read/write) List of the inner values in a single row.
                            Inner values are attached to the object without any property's name.
                            Example : CONFIG, PROJECTION, SYMBOL, METADA,... 
 ->children                 (read only) An indexed array of all MapFileObject children.
 ->props                  (read only) An associative array or properties => values.
 ->setComment($comment)   Set a comment to the current object.
 ->setProp($prop, $value, $strDelim=false, $check=false)
                          Set a property to the current object.
                          A property is a tag with a single value which is not an object (numer, string, MapServer expression, MapServer regexp)
                          $value must be set wihtout escaping or string delimited.
                          Put $strDelim to true if the value has to be string delimited for this property (such as an URL, or a file name).
 ->getProp($prop, $default = false) Get a property of the current object, or $default if missing.
 ->countChildren($type)   Return the number of children of the given type.
 ->getChild($type, $nameOrIndex = 1, $nameProp = 'NAME') Return the object corresponding to the criterias.
 ->getChildren($type)     Return an array of all children object of the given type. 
 ->deleteChildren($type)  Delete all child of the given type.
 ->addChildren($arr)      Add one child or an array of children to the current object.
 ->asString()             Return the MapFile source for this object, including and its children.
 ->saveInFile($file)      Save the object in the file.

 ->escapeString($txt)     Escape a string. Do not use it for setProp()
 ::colorHex2Ms()          Convert a '#hhhhhh' color into 'r g b'.   Note that MapServer supports format '#hhhhhh'.
 ::colorMs2Hex()          Convert a 'r g b'   color into '#hhhhhh'. Note that MapServer supports format '#hhhhhh'.

--------------
MapFileSynopsis class:
--------------
 It's a core class that contains the dictionary of MapFile special keyswords.
 Keywords that are not in the synopsis are supposed to be simple MapFile properties (a keyword with a single value).

 
