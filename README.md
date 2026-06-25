# Nexus — Plateforme multi-tenant d'agents IA avec garde-fous anti-hallucination

> Système complet, en production locale, qui fait tourner des agents LLM sur des données métier **sans jamais les laisser inventer un chiffre**. Le LLM rédige ; le serveur calcule et verrouille.

<!-- Remplace ces deux lignes -->
**Auteur :** {{TON_NOM}} · **Démo / code :** {{URL_DU_DEPOT}}
**Stack :** PHP 8.x · SQLite / MySQL (même code, abstraction PDO) · OpenRouter (multi-modèles) · SSE · architecture cloud-ready

---

## Le problème que ce projet résout

La plupart des projets qui mettent un LLM en production se cassent les dents sur le même mur : **le modèle invente des chiffres**. Un rendement « net supérieur au brut », un total qui ne tombe pas juste, une donnée extrapolée alors qu'elle était absente de la source. En B2B, un seul chiffre faux dans un e-mail envoyé à un client, et la crédibilité est morte.

Ce projet apporte une réponse concrète et testée à ce problème, à travers un pattern simple :

> **Le LLM ne calcule jamais. Le serveur calcule, verrouille le résultat, et le LLM ne fait que rédiger autour de valeurs déjà vérifiées.**

Tout le reste de l'architecture (multi-tenant, file de jobs, agents pilotés par config) existe pour rendre ce pattern exploitable sur de vrais flux métier.

---

## Le pattern anti-hallucination, en concret

Cas réel utilisé comme banc d'essai : le **calcul de rendement immobilier**.

**Sans garde-fou** — on demande au modèle de « calculer et présenter le rendement » :

```
Rendement net : 4,1 %   ← supérieur au brut (3,4 %). Impossible.
Loyer net : inventé à partir d'une annonce qui ne le contenait pas.
```

**Avec le pattern** — le serveur fait le calcul déterministe, le LLM ne touche jamais aux nombres :

```
1. extractYieldInputs()   → lit l'annonce. Prix absent ? Loyer net absent ?
                            → retourne null. On NE calcule PAS. (pas d'invention)
2. computeYield()         → calcul serveur déterministe, testé sur 200 cas :
                            net ≤ brut GARANTI par construction.
3. Le LLM reçoit les chiffres déjà figés et rédige seulement la prose autour.
```

Exemple de sortie verrouillée (Bulle, 595 000 CHF, loyer net 1 670 CHF/mois) :

```json
{
  "rendement_brut_pct": 3.37,
  "rendement_net_pct": 2.18,
  "charges": { "entretien": 5950, "gerance": 802, "vacance": 301 },
  "hypotheses": { "entretien_pct_prix": 1, "gerance_pct_loyer": 4, "vacance_pct_loyer": 1.5 }
}
```

Deux barrières serveur complètent le dispositif :

- `detectSalePrice` — si le prix de vente est absent, le calcul de rendement est **interdit** (on ne chiffre pas une location comme un investissement).
- `outreachInventsYield` — relit le texte généré et bloque toute affirmation de rendement non adossée à un calcul serveur.

L'agent « comptable » tourne à **température 0** avec un prompt qui impose `null` pour toute donnée absente — interdiction explicite d'extrapoler.

---

## Architecture

**Multi-tenant, isolation physique.** Chaque projet vit dans `projects/<nom>/` avec son propre `config.json` et sa propre base de données. Changer de projet recharge l'historique, les compteurs et les coûts spécifiques ; un projet ne voit jamais les données d'un autre (vérifié au test).

**Agents pilotés par config.** Trois rôles spécialisés — éclaireur (collecte sur flux autorisés), comptable (extraction stricte), vendeur (rédaction) — dont le modèle, le prompt et les plafonds sont définis dans le `config.json`, sans toucher au code. Sélection de modèle par palier (`flash` / `expert`).

**Cœur découplé du web.** `nexus_core.php` contient toute la logique (appels modèle, scraping, base, coûts, agents) et s'appelle aussi bien depuis le web que depuis la ligne de commande — ce qui débloque les tâches planifiées (cron). `nexus.php` n'est que le routeur web (auth, contexte projet, streaming SSE).

**Abstraction base de données réelle.** Le même code, sans une ligne modifiée, tourne sur **SQLite et MySQL** — on bascule `db.type` dans la config. La seule divergence est isolée dans une fonction DDL ; tout le reste passe par des requêtes préparées identiques. La base MySQL est auto-créée si absente.

**Sécurité des flux.** Garde-fous SSRF (refus des URL internes), respect de `robots.txt`, cache de pages, lecture multi-format (XML / JSON / CSV) sur les seuls flux explicitement autorisés.

**Suivi des coûts** par projet, avec budget et plafond d'envois quotidiens.

---

## Stack technique

| Domaine | Choix |
|---|---|
| Langage | PHP 8.x |
| Base de données | SQLite (local) / MySQL (prod) — abstraction PDO |
| Modèles | OpenRouter (paliers Gemini Flash / Hermes) |
| Temps réel | Server-Sent Events (streaming) |
| Déploiement | XAMPP en local, cloud-ready pour VPS (ex. Infomaniak) |

---

## Ce que ce projet démontre

- Concevoir un système d'agents IA **fiable en production**, où l'IA ne peut pas inventer de données métier.
- Séparer proprement **calcul déterministe** et **génération de langage** — le bon découpage des responsabilités.
- Une architecture **multi-tenant** avec isolation réelle des données.
- Une **abstraction de persistance** testée sur deux moteurs.
- Le réflexe de **tester les garde-fous eux-mêmes** (200 cas sur l'invariant net ≤ brut, smoke tests sur chaque branche).

---

## Structure du dépôt

```
nexus_core.php   Cœur métier (agents, modèles, base, coûts) — web + CLI
nexus.php        Routeur web (auth, contexte projet, SSE)
index.html       Interface
login.html       Authentification
.htaccess        Protection (données projet inaccessibles directement)
projects/        Un dossier par tenant (config + base isolées)
```

---

## Contexte

Projet personnel conçu et développé de bout en bout pour explorer la mise en production fiable de LLM. Le cas d'usage immobilier sert de banc d'essai concret au pattern anti-hallucination ; l'architecture est volontairement agnostique et réutilisable pour tout domaine où l'IA doit produire des sorties adossées à des données vérifiées.
