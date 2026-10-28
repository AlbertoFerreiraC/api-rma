-- Ejecutar en dosisma_rma_bd.
CREATE TABLE IF NOT EXISTS bajas_stock (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 id_stock INT NOT NULL,
 id_caso INT NULL,
 cantidad INT NOT NULL,
 stock_anterior INT NOT NULL,
 stock_nuevo INT NOT NULL,
 id_usuario INT NOT NULL,
 causa VARCHAR(30) NOT NULL,
 motivo TEXT NOT NULL,
 fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_bajas_stock (id_stock),
 CONSTRAINT fk_bajas_insumo FOREIGN KEY (id_stock) REFERENCES stock(id),
 CONSTRAINT fk_bajas_caso FOREIGN KEY (id_caso) REFERENCES casos(id),
 CONSTRAINT fk_bajas_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
