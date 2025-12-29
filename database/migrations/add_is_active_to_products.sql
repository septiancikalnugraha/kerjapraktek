-- Add is_active column to products table
ALTER TABLE `products` 
ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 
COMMENT '1 = active, 0 = inactive' 
AFTER `keterangan`;

-- Update existing products to be active by default
UPDATE `products` SET `is_active` = 1;
