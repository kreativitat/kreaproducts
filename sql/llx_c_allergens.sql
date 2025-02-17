-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: database:3306
-- Tempo de geração: 16-Fev-2025 às 18:36
-- Versão do servidor: 10.6.21-MariaDB-ubu2004
-- versão do PHP: 8.2.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de dados: `degemapt_doli391`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `llxnm_c_allergens`
--

CREATE TABLE `llxnm_c_allergens` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(5) NOT NULL,
  `label` varchar(255) NOT NULL,
  `icon` varchar(120) NOT NULL,
  `active` int(3) DEFAULT 1,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Inserindo dados corrigidos
--

INSERT INTO `llxnm_c_allergens` (`label`, `icon`, `active`, `code`) VALUES
('Sem alergenios', 'noallergens.png', 0, 'NOAL'),
('Aipo', 'celery.png', 1, 'AIPO'),
('Amendoim', 'peanut.png', 1, 'AMEN'),
('Crustaceos', 'crustacens.png', 1, 'CRUS'),
('Frutos de casca rija', 'treenut.png', 1, 'FRUT'),
('Gluten', 'wheat.png', 1, 'GLUT'),
('Leite', 'milk.png', 1, 'LEIT'),
('Moluscos', 'molluscs.png', 1, 'MOLU'),
('Mostarda', 'mustard.png', 1, 'MOST'),
('Ovos', 'eggs.png', 1, 'OVOS'),
('Peixe', 'fish.png', 1, 'PEIX'),
('Sementes de sesamo', 'sesame.png', 1, 'SESA'),
('Soja', 'soya.png', 1, 'SOJA'),
('Sulfitos', 'sulphurdioxide.png', 1, 'SULF'),
('Tremoços', 'lupin.png', 1, 'TREM');



--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `llxnm_c_allergens`
--
ALTER TABLE `llxnm_c_allergens`
  ADD PRIMARY KEY (`rowid`);

--
-- AUTO_INCREMENT de tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `llxnm_c_allergens`
--
ALTER TABLE `llxnm_c_allergens`
  MODIFY `rowid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
