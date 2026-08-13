<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts para Dolibarr ERP/CRM

KreaProducts é um módulo avançado para gestão de produtos no [Dolibarr ERP/CRM](https://www.dolibarr.org). Amplia o módulo de Produtos com nutrição, alérgenos, BOM/Fichas Técnicas, inventário e automatizações de custos e stock — pensado para operações de restauração e varejo que precisam de consistência, rastreabilidade e _food cost_ sempre correto.

## Funcionalidades

### Nutrição e alérgenos

- Tabela nutricional com cálculo, validação e atualização automática.
- Propagação de nutrientes entre produtos pai/filho, incluindo BOM (MRP) quando ativo.
- Gestão de alérgenos com propagação por percentual do peso total e marcação de traços.
- Suporte a produtos não alimentares (excluídos do cálculo).

### Estrutura de produtos e BOM

- Árvore completa de produtos (associações + BOM/MRP, quando ativo), com navegação hierárquica.
- Visualização detalhada da composição do produto (componentes, quantidades e subprodutos), com totalização do **preço de custo**.
- Identificação clara de relações entre produtos e fichas técnicas, incluindo **embalagens de origem** quando aplicável.
- Visão inversa (_onde é usado_): listagem dos kits/fichas técnicas e menus onde o item entra como componente, permitindo avaliar o impacto de alterações de custo, substituições e normalização de matérias-primas.
- BOM de desmontagem com origem e relacionamentos visíveis na ficha técnica.
- Recalculo automático de custos em cascata baseado em constituintes/fichas técnicas.
- Suporte a BOM encadeada (linhas que referenciam outra BOM), com propagação correta de custos, nutrição e alérgenos.
- Multiempresa: BOM compartilhada (entity=0) disponível em todas as entidades, com prioridade para a BOM da entidade atual quando existe.
- Desmontagem controlada por produto via campo extra `kreap_dismantle`.

### Datas corretas de stock e inventário (data da fatura e data-valor)

O Dolibarr, por padrão, registra muitos movimentos **na data em que o documento é lançado/validado no sistema** — o que pode não coincidir com a realidade operacional. Em ambientes com compras frequentes, essa diferença cria desvios e ruído na análise de stock.

O KreaProducts corrige essa limitação com duas automatizações essenciais:

- **Entrada de stock pela data da fatura (fornecedores):** os produtos são lançados em stock com a **data da fatura/data de entrada**, em vez da data em que o documento é registrado no Dolibarr. Isso elimina discrepâncias quando a fatura é registrada dias depois.
- **Inventário por data-valor (retroativo):** o ajuste do inventário é aplicado com base na **data do inventário (data-valor)**, e não na data de validação. Dessa forma, é possível lançar um inventário com data-valor anterior (por exemplo, de uma semana atrás) e garantir que as correções e os relatórios permaneçam consistentes — algo que o módulo padrão não garante.
- **Recalculo por inventário físico:** o stock é recalculado com base na **quantidade contada** (qty_stock) quando disponível, usando qty_view apenas como fallback — evitando desvios em retroativos.

### Gestão inteligente de embalagens e custo unitário (desmontagem automática)

Na restauração, é comum comprar o mesmo item em embalagens diferentes — mas para o _food cost_ o que importa é o **custo unitário real** (ex.: EUR/L, EUR/kg, EUR/un).

Exemplo típico: **óleo**. Pode ser comprado em **galões de 10L, 5L, 1L** ou **caixas 12x1L**. Se essas embalagens entrarem no sistema como "produtos diferentes", rapidamente surgem inconsistências de stock e custo por unidade.

O KreaProducts resolve isso por meio do módulo **BOM do Dolibarr (Listas de Materiais / Ficha de Materiais – FM)**:

- Configura-se uma FM para a embalagem (ex.: _galão 10L_), definindo a conversão para o produto unitário (ex.: _10x 1L_).
- A partir desse momento, sempre que é registrada a compra de uma dessas embalagens, o sistema realiza o **desmontagem automática** para o produto unitário, **sem intervenção do usuário**.

Esse processo:

- cria os **movimentos de stock** correspondentes,
- mantém o **custo proporcional** e a rastreabilidade (origem -> destino),
- e garante que o produto unitário fique pronto para uso em receitas, inventário e cálculos de margem.

### Atualização automática de custos e _food cost_ (em cascata)

O KreaProducts automatiza ainda a atualização do **preço de custo** e do **food cost** dos produtos finais, com base nas respectivas fichas técnicas (BOM/FM).

Na prática:

- se um constituinte (ex.: **óleo**) tiver o preço de compra atualizado,
- todos os produtos onde esse constituinte é usado (ex.: **batatas fritas**) têm seu **custo recalculado automaticamente**,
- garantindo que o _food cost_ e as margens reflitam sempre a realidade, sem ajustes manuais.

Essa funcionalidade é especialmente relevante em operações com muitas receitas e compras frequentes, onde pequenas variações de custo devem se refletir de imediato nos produtos finais.

### Produtividade e listas

- Lista de produtos simplificada com opção de ocultar itens.
- Simulador de preços (Métricas e Margens) com markup de teste.
- Lista de movimentos de stock por produto com **stock total**.

## Requisitos

- Dolibarr >= 19
- PHP >= 7.0
- Módulos obrigatórios: Produtos, Stock, Fornecedores, BOM/MRP
- Opcional: Lotes (productbatch)

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
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Mostrar o seletor e botão para copiar valores médios por 100 g. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Mostrar o seletor e botão para copiar alérgenos para outro produto. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Propagar automaticamente o preço de custo (recalculo em cascata). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Percentual do peso total para considerar alérgenos como presentes. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Percentual do peso total para marcar alérgenos como traços. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Usar data da fatura nos movimentos de stock. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Hora aplicada a movimentos de fatura de fornecedor. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Hora padrão ao criar inventário. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Categoria raiz para seleção de inventário. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | Tipo de BOM usado na desmontagem. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Armazém para movimentos de desmontagem. |
| `KREAPRODUCTS_SIM_ENABLE` | Ativar simulador de preços. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Markup padrão do simulador. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Substituir a lista padrão de produtos. |
| `KREAPRODUCTS_DEBUG_LOG` | Ativar logs de debug do KreaProducts. |

Nota: os limiares de alérgenos são percentuais do peso total da receita do produto final.

## Permissões

- Nutrição: leitura, escrita, remoção.
- Alérgenos: leitura, escrita, remoção.
- Inventário: ver valores esperados.

## Licença

- GPL-3.0-or-later (ver LICENSE e COPYING).
- Licença proprietária disponível para uso comercial ou código fechado; contate mail@kreativitat.com.

## Suporte e contribuições

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com
- Demo: https://dolibarr.kreativitat.com

## Aviso legal

Os dados de nutrição e alérgenos são inseridos pelo usuário ou derivados das suas entradas e não são verificados. São fornecidos apenas para fins informativos e não constituem aconselhamento médico, dietético ou regulatório. O usuário é o único responsável pela exatidão, rotulagem e conformidade com a legislação aplicável. Este módulo é fornecido "tal como está", sem garantias de qualquer tipo, expressas ou implícitas, incluindo comercialização e adequação a um fim específico. Na máxima extensão permitida por lei, os autores e distribuidores não se responsabilizam por quaisquer danos diretos ou indiretos decorrentes do uso dos dados ou do software.

## Capturas de tela

![KreaProducts - Tela 1](img/screenshot_1.png)

![KreaProducts - Tela 2](img/screenshot_2.png)
