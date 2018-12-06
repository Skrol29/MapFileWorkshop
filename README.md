# MapFileWorkshop

MapFileWorkshop is a PHP library for reading, searching, editing and creating MapFiles for MapServer version 5 to 7 and higher.
It's peculiarity is that no regular expression is used. The MapFile is read with respect to
the MapServer syntax, but without understanding the meaning. The dictionary of special keyswords
is stored into a Synopsis class. The Synopsis class can be updated if needed in futur MapServer versions. 

The MapFileWorkshop library is also a nice tool to find syntax errors in an existing MapFile.

This library is case insensitive for reading a source file.
But when using a MapFileObject, all MapServer keywords must be specified UPPERCASE.

```
@example #1

$map = new MapFileObject::getFromFile('my_source_file.map');
$layer = $map->getChild('LAYER', 'my_sweet_layer');
echo $layer->getProp('DATA'); // reading a property
echo $layer->asString(); // get the MapServer definition

@example #2

$map = new MapFileObject::getFromFile('my_source_file.map', 'MAP/LAYER:my_sweet_layer');
```

See the source code header for a more detailed synopsis.
 
