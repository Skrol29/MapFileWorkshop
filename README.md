# MapFileWorkshop

MapFileWorkshop is a PHP library for reading, editing and creating MapFiles for MapServer version 5 to 7 and higher.
It's peculiarity is that no regular expression is used. The MapFile is read with respect to
the MapServer syntax, but without understanding the meaning. The dictionary of special keyswords
is stored into a Synopsis class. The Synopsis class can be updated if needed in futur MapServer versions. 

The MapFileWorkshop library is also a nice tool to find syntax errors in an existing MapFile.

This library can read MapFile sources with case insensitive keyworks, but all objects of this library will be modelised with UPPERCASE keywords.
So you have to use UPPERCASE keywords for managing objects, while your MapFile can stay case insensitive.

```
@example #1

$map = new MapFileObject::getFromFile('my_source_file.map'); // read and load the complete MAP definition in memory, i.e. including all layers
$layer = $map->getChild('LAYER', 'my_sweet_layer');
echo $layer->getProp('DATA'); // reading a property
echo $layer->asString(); // get the MapServer definition;

@example #2

$ws = new MapFileWorkshop('my_source_file.map'); // the source file is not sought at this point
$layer = $ws->searchObj('LAYER', 'my_sweet_layer'); // stop the reading when the object is found, load only the found object in memory.
$class = $layer->getChild('CLASS', 1);
$style = $class->getChild('STYLE', 1);
$opacity = $style->getProp('OPACITY');
$style->setProp('OUTLINECOLOR', $style->colorHex2Ms('FFFFFF'));
$style->setComment("modified by the demo");
$ws->replaceDef($style->getSrcPosition(), $style); // replace the object in the target file
```

See the source code header for a more detailed synopsis.
 
