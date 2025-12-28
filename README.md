<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->
# KreaProducts para Dolibarr ERP/CRM

KreaProducts é um módulo avançado para gestão de produtos no [Dolibarr ERP/CRM](https://www.dolibarr.org). Ele amplia o módulo de produtos com nutrição, alergénios, BOM, inventário e automatizações de custos e stock.

## Funcionalidades

### Nutrição e alergénios
- Tabela nutricional com cálculo, validação e atualização automática.
- Propagação de nutrientes entre produtos pai/filho e suporte a produtos não alimentares.
- Gestão de alergénios e vestígios na ficha do produto.

### Estrutura de produtos e BOM
- Árvore completa de produtos (associações + BOM MRP, quando ativo).
- BOM de montagem e desmontagem com origem e relacionamentos visíveis na ficha técnica.
- Recálculo automático de custos em cascata baseado em filhos/BOM.

### Inventário e stock
- Pré-preenchimento de linhas de inventário com stock atual.
- Referência de inventário baseada em data e categoria (quando configurado).
- Folha de inventário em PDF e bloqueio de alterações após fecho.
- Ajustes de movimentos de stock em faturas de fornecedor e inventários (configurável).

### Produtividade e listas
- Lista de produtos simplificada com opção de ocultar itens.
- Simulador de preços (Métricas e Margens) com markup de teste.

## Requisitos
- Dolibarr >= 11
- PHP >= 7.0
- Módulos obrigatórios: Produtos, Stock, Fornecedores
- Opcional: BOM/MRP, Lotes (productbatch)

## Instalação
1. Copiar o módulo para `custom/kreaproducts`.
2. Ativar em Configuração -> Módulos/Aplicações -> KreaProducts.
3. Ajustar as opções na página de configuração.
4. Se necessário, importar os scripts em `sql/`.

## Configuração (principais constantes)
| Constante | Descrição |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Classe de unidades para peso. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Mostrar tabela nutricional na ficha técnica. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Propagar automaticamente o preço de custo. |
| `KREAPRODUCTS_AUTO_SYNCH_NUT_TABLE` | Propagar automaticamente a tabela nutricional. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Usar data da fatura nos movimentos de stock. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Hora aplicada a movimentos de fatura de fornecedor. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Hora padrão ao criar inventário. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Categoria raiz para seleção de inventário. |
| `KREAPRODUCTS_DISMANTLE_CATEGORY` | Categoria que ativa desmontagem automática. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | Tipo de BOM usado na desmontagem. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Armazém para movimentos de desmontagem. |
| `KREAPRODUCTS_SIM_ENABLE` | Ativar simulador de preços. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Markup predefinido do simulador. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Substituir a lista padrão de produtos. |

## Permissões
- Nutrição: leitura, escrita, remoção.
- Alergénios: leitura, escrita, remoção.
- Inventário: ver valores esperados.

## Licença
- GPL-3.0-or-later (ver `LICENSE` e `COPYING`).
- Licença proprietária disponível para uso comercial ou código fechado; contacte `mail@kreativitat.com`.

## Suporte e contribuições
- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com
