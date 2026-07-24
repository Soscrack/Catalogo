Quiero que revises que la base de datos de los productos este bien estructurada. Para esto debemos tener productos locales que son heredados del legacy de la tienda local, además estos productos se han de vender en la tienda local. 
Debemos tener productos online estos por ahora tienen un template de un catalogo de mamut
Debe haber productos guardados por los códigos y descripciones de los proovedores que se reciben por XML.
Los productos de los proveedores se le puede asignar o un producto local o una familia. Los productos de proveedores pueden tener asociado solo un producto online

Debe soportar un sistema de punto de ventas con modalidad online y local,donde en online tome los precios online para cada producto.
Para esto lee el código de barra ve el producto, el envase y cantidad. y ve el precio asignado.
El sistema local asigna el precio de referencia a la familia  luego con respecto a una regla de la familia asigna el precio respecto a la regla usando la cantidad de productos de la familia que lleva, ejemplo: lleva 3 cajas de 50 y 2 de 100 de 02Tadb. En modo online le buscaría el precio por separado. En modo local vería el precio para 350 02tadb, por ejemplo puede la regla ser menor  300, precio refereni + 3 pesos, redondeando a la decena superior.

Los códigos de barra han de tener asociado el cod del proveedor.
Vee si la arquitectura cumple esto y da sugerencias