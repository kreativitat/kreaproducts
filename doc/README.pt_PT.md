<!-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com> -->

# KreaProducts para Dolibarr ERP/CRM

KreaProducts é um módulo avançado de gestão de produtos para o [Dolibarr ERP/CRM](https://www.dolibarr.org). Amplia o módulo Produtos com nutrição, alergénios, BOM/fichas técnicas, inventário e automatizações de custos e stock — para operações de restauração e retalho que exigem consistência, rastreabilidade e um custo alimentar rigoroso.

## Funcionalidades

### Nutrição e alergénios

- Tabela nutricional com cálculo, validação e atualização automática.
- Propagação de nutrientes entre produtos pai/filho, incluindo BOM (MRP) quando ativo.
- Gestão de alergénios com propagação por percentagem do peso total e marcação de traços.
- Suporte a produtos não alimentares (excluídos do cálculo).

### Estrutura de produtos e BOM

- Árvore completa de produtos (associações + BOM/MRP, quando ativo), com navegação hierárquica.
- Visualização detalhada da composição do produto (componentes, quantidades e subprodutos), com totalização do **preço de custo**.
- Identificação clara de relações entre produtos e fichas técnicas, incluindo **embalagens fonte** quando aplicável.
- Vista inversa (_onde é utilizado_): listagem dos kits/fichas técnicas e menus onde o artigo entra como componente, permitindo avaliar o impacto de alterações de custo, substituições e normalização de matérias-primas.
- BOM de desmontagem com origem e relacionamentos visíveis na ficha técnica.
- Recálculo automático de custos em cascata baseado em constituintes/fichas técnicas.
- Suporte a BOM encadeada (linhas que referenciam outra BOM), com propagação correta de custos, nutrição e alergénios.
- Multiempresa: BOM partilhada (entity=0) disponível em todas as entidades, com prioridade para a BOM da entidade atual quando existe.
- Desmontagem controlada por produto via campo extra `kreap_dismantle`.

### Datas corretas de stock e inventário (data da fatura e data-valor)

O Dolibarr, por defeito, regista muitos movimentos **na data em que o documento é lançado/validado no sistema** — o que pode não coincidir com a realidade operacional. Em ambientes com compras frequentes, esta diferença cria desvios e ruído na análise de stock.

O KreaProducts corrige esta limitação com duas automações essenciais:

- **Entrada de stock pela data da fatura (fornecedores):** os produtos são lançados em stock com a **data da fatura/data de entrada**, em vez da data em que o documento é registado no Dolibarr. Isto elimina discrepâncias quando a fatura é registada dias depois.
- **Inventário por data-valor (retroativo):** o ajuste do inventário é aplicado com base na **data do inventário (data-valor)**, e não na data de validação. Desta forma, é possível lançar um inventário com data-valor anterior (por exemplo, de há uma semana) e assegurar que as correções e os relatórios permanecem consistentes — algo que o módulo padrão não garante.
- **Recálculo por inventário físico:** o stock é recalculado com base na **quantidade contada** (`qty_stock`) quando disponível, usando `qty_view` apenas como alternativa — evitando desvios em lançamentos retroativos.

### Gestão inteligente de embalagens e custo unitário (desmantelamento automático)

Na restauração, é comum comprar o mesmo artigo em embalagens diferentes — mas para o custo alimentar o que interessa é o custo **unitário real** (ex.: €/L, €/kg, €/un.).

Exemplo típico: **óleo**. Pode ser comprado em **garrafões de 10L, 5L, 1L** ou **caixas 12×1L**. Se estas embalagens entrarem no sistema como “produtos diferentes”, rapidamente surgem inconsistências de stock e custo por unidade.

O KreaProducts resolve isto através do módulo **BOM do Dolibarr (Listas de Materiais / Ficha de Materiais – FM)**:

- Configura-se uma FM para a embalagem (ex.: _garrafão 10L_), definindo a conversão para o produto unitário (ex.: _10× 1L_).
- A partir desse momento, sempre que é registada a compra de uma dessas embalagens, o sistema procede ao **desmantelamento automático** para o produto unitário, **sem intervenção do utilizador**.

Este processo:

- cria os **movimentos de stock** correspondentes,
- mantém o **custo proporcional** e a rastreabilidade (origem → destino),
- e garante que o produto unitário fica pronto para utilização em receitas, inventário e cálculos de margem.

### Atualização automática de custos e custo alimentar (em cascata)

O KreaProducts automatiza ainda a atualização do **preço de custo** e do **custo alimentar** dos produtos finais, com base nas respetivas fichas técnicas (BOM/FM).

Na prática:

- se um constituinte (ex.: **óleo**) tiver o preço de compra atualizado,
- todos os produtos onde esse constituinte é usado (ex.: **batatas fritas**) têm o seu **custo recalculado automaticamente**,
- garantindo que o custo alimentar e as margens refletem sempre a realidade, sem ajustes manuais.

Esta funcionalidade é especialmente relevante em operações com muitas receitas e compras frequentes, onde pequenas variações de custo devem refletir-se de imediato nos produtos finais.

### Produtividade e listas

- Lista de produtos simplificada com opção de ocultar itens.
- Simulador de preços (Métricas e Margens) com markup de teste.
- Lista de movimentos de stock por produto com indicação de **stock total**.

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

| Constante                                   | Descrição                                                            |
| ------------------------------------------- | -------------------------------------------------------------------- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL`         | Classe de unidades para peso.                                        |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB`        | Mostrar tabela nutricional na ficha técnica.                         |
| `KREAPRODUCTS_LLM_PROVIDER`                 | Fornecedor de IA para sugestões de nutrição e alergénios: OpenAI, Claude, OpenRouter ou Ollama. |
| `KREAPRODUCTS_LLM_MODEL`                    | Identificador exato do modelo do fornecedor selecionado.               |
| `KREAPRODUCTS_LLM_API_KEY`                  | Chave API cifrada do fornecedor; não é necessária para Ollama.   |
| `KREAPRODUCTS_LLM_OLLAMA_URL`               | URL base local/privada do Ollama (por defeito `http://localhost:11434`). |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT`   | Mostrar o seletor e botão para copiar valores médios por 100 g.       |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Mostrar o seletor e botão para copiar alergénios para outro produto. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE`         | Propagar automaticamente o preço de custo (recálculo em cascata).    |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT`  | Percentagem do peso total para considerar alergénios como presentes. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Percentagem do peso total para marcar alergénios como traços.        |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA`          | Usar data da fatura nos movimentos de stock.                         |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME`           | Hora aplicada a movimentos de fatura de fornecedor.                  |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME`       | Hora padrão ao criar inventário.                                     |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT`      | Categoria raiz para seleção de inventário.                           |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE`            | Tipo de BOM usado na desmontagem.                                    |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE`          | Armazém para movimentos de desmontagem.                              |
| `KREAPRODUCTS_SIM_ENABLE`                   | Ativar simulador de preços.                                          |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP`           | Markup predefinido do simulador.                                     |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST`         | Substituir a lista padrão de produtos.                               |
| `KREAPRODUCTS_DEBUG_LOG`                    | Ativar registos de diagnóstico do KreaProducts.                      |

Nota: os limiares de alergénios são percentagens do peso total da receita do produto final.

As sugestões de IA ficam disponíveis apenas quando a nutrição e os alergénios do produto estão no modo manual. Use o botão **Sugestão por IA** ao lado de **Guardar** na tabela nutricional para abrir o modal. A página do produto carrega os ingredientes e descrições existentes. Quando não existem valores exatos do rótulo, o fornecedor pode estimar a nutrição típica por 100 g a partir de conhecimento geral de composição alimentar e deve indicar confiança baixa e as respetivas premissas. Os alergénios continuam limitados a ingredientes e vestígios explicitamente declarados. A resposta estruturada é validada e exige revisão explícita antes de substituir os dados numa única transação. Os endereços Ollama estão limitados à máquina local ou à rede privada.

## Permissões

- Nutrição: leitura, escrita, remoção.
- Alergénios: leitura, escrita, remoção.
- Inventário: ver valores esperados.

## Licença

- GPL-3.0-or-later (ver LICENSE e COPYING).

## Suporte e contribuições

- GitHub: https://github.com/kreativitat
- Site: https://www.kreativitat.com
- Demonstração: https://dolibarr.kreativitat.com

## Aviso legal

Os dados de nutrição e alergénios são inseridos pelo utilizador ou derivados das suas entradas e não são verificados. São fornecidos apenas para fins informativos e não constituem aconselhamento médico, dietético ou regulamentar. O utilizador é o único responsável pela exatidão, rotulagem e conformidade com a legislação aplicável. Este módulo é fornecido "tal como está", sem garantias de qualquer tipo, expressas ou implícitas, incluindo comercialização e adequação a um fim específico. Na máxima extensão permitida por lei, os autores e distribuidores não se responsabilizam por quaisquer danos diretos ou indiretos decorrentes do uso dos dados ou do software.

## Capturas de ecrã

![KreaProducts - Ecrã 1](img/screenshot_1.png)

![KreaProducts - Ecrã 2](img/screenshot_2.png)
