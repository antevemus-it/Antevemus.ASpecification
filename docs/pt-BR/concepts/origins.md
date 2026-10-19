# 🏛️ Origem & Fundamentação Teórica

O **Antevemus ASpecification** foi concebido sobre sólidos pilares de engenharia de software e Domain-Driven Design (DDD):

1. **O Paper Seminal Original**:
   Baseado no artigo clássico [The Specifications Pattern (PDF)](http://www.martinfowler.com/apsupp/spec.pdf), de **Eric Evans e Martin Fowler (2002)**, que formalizou o encapsulamento de predicados e regras de negócio em objetos combináveis de primeira classe para:
   - **Validação de Objetos**: Verificar se um objeto atende a critérios específicos.
   - **Seleção e Filtragem**: Consultar entidades em repositórios sem vazar SQL ou detalhes de infraestrutura.
   - **Construção e Satisfação de Restrições**: Especificar o que é necessário para instanciar ou transicionar entidades.

2. **A Biblioteca de Origem: Domian (Java)**:
   Este projeto é uma portagem PHP independente e não oficial, com evolução moderna, do framework [Domian (Domain-Driven Design for Java)](https://domian.sourceforge.net/index.html), criado originalmente por **Eirik Torske** e **Bjørn Nordlund**, distribuído sob a **Apache License, Version 2.0**. Não é afiliado, mantido nem endossado pelos autores do Domian: o desenho, a API pública e a semântica seguem as fontes públicas do Domian, e o código PHP foi escrito pela Antevemus. O Domian foi pioneiro em unificar álgebra booleana, teoria dos conjuntos (Venn), especificações de coleções, arquitetura de repositórios particionados em Grafo Acíclico Dirigido (DAG) e sincronização concorrente.

## 📚 Referências & Bibliografia

- **[Domian Specification Framework](https://domian.sourceforge.net/index.html)**: Projeto Java original criado por Eirik Torske.
- **[The Specifications Pattern - Eric Evans & Martin Fowler](http://www.martinfowler.com/apsupp/spec.pdf)**: Artigo fundamental que introduziu o padrão.
- **[Domain-Driven Design: Tackling Complexity in the Heart of Software](https://www.domainlanguage.com/ddd/)**: Obra de referência de Eric Evans sobre modelagem de domínio rica.
- **[Design Patterns: Elements of Reusable Object-Oriented Software](https://en.wikipedia.org/wiki/Design_Patterns)**: Padrões GoF de referência (Composite, Decorator, Visitor, Factory).
