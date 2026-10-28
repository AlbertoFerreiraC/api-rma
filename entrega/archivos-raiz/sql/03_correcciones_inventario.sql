-- Ejecutar en dosisma_inventario_db (también disponible por herramientas/instalar_correcciones.php).
CREATE TABLE IF NOT EXISTS operaciones_rma (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 producto_id INT NOT NULL,
 id_caso INT NULL,
 numero_caso VARCHAR(30) NULL,
 id_tecnico INT NULL,
 tipo VARCHAR(30) NOT NULL,
 cantidad INT NOT NULL,
 stock_anterior INT NOT NULL,
 stock_nuevo INT NOT NULL,
 rma_anterior INT NOT NULL,
 rma_nuevo INT NOT NULL,
 id_usuario INT NOT NULL,
 motivo TEXT NOT NULL,
 fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_operaciones_caso (producto_id,id_caso,tipo),
 CONSTRAINT fk_operaciones_producto FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
