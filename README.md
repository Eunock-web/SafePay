# 🛡️ SafePay pour Laravel

Un package Laravel robuste pour intégrer facilement le système de paiement et de séquestre (Escrow) de **FedaPay** au sein de vos applications. C'est la solution idéale pour les plateformes, marketplaces et services nécessitant une rétention sécurisée des fonds avant validation et livraison.

---

## 📋 Prérequis

Avant d'installer le package, assurez-vous que votre environnement respecte les exigences minimales suivantes :

- **PHP** : 8.1 ou supérieur
- **Laravel** : 10.0 ou supérieur
- **Base de données** : Votre modèle `User` doit impérativement utiliser des **UUID** en tant que clé primaire (au lieu de l'auto-incrément classique).

---

## 🚀 Installation

Installez le package directement via Composer en utilisant la commande suivante :

```shell
composer require eunockweb/safepay
```

Une fois le package installé, publiez les configurations et les migrations fournies par le package :

```shell
php artisan vendor:publish --provider="Safepay\SafePayServiceProvider"
```

Appliquez ensuite les migrations pour créer les tables nécessaires (`transactions` et `transaction_logs`) :

```shell
php artisan migrate
```

---

## ⚙️ Configuration

Pour que le package puisse communiquer avec l'API FedaPay, vous devez ajouter et configurer les variables suivantes dans votre fichier `.env` :

```env
# Clé secrète de votre compte FedaPay
FEDAPAY_SECRET_KEY="sk_sandbox_xxxxxxxxxxxxxxxxx"

# Environnement FedaPay ('sandbox' pour l'environnement de test, 'live' en production)
FEDAPAY_ENVIRONMENT="sandbox"

# Clé secrète de votre Webhook (trouvable dans votre dashboard FedaPay)
FEDAPAY_WEBHOOK_SECRET="whsec_xxxxxxxxxxxxxxxxx"
```

---

## 🛠️ Mise en place

Pour permettre à vos utilisateurs d'interagir nativement avec le système Escrow, ajoutez simplement le trait `HasPayments` à votre modèle `User` typique (`app/Models/User.php`) :

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Safepay\Traits\HasPayments;

class User extends Authenticatable
{
    use HasPayments; // <-- Ajouter cette ligne

    // ... reste de votre modèle
}
```

---

## 💻 Utilisation

Le trait `HasPayments` ainsi que le package vous offrent des raccourcis intuitifs pour la gestion des fonds. Voici un aperçu des méthodes principales :

### 1. Bloquer des fonds (Charge Escrow)

Permet d'initier une transaction où l'argent du client sera bloqué dans le système.

```php
$user = auth()->user();
$prestataireId = 'uuid-du-prestataire';
$transactionId = 'fedapay_tx_123456'; // L'identifiant propre à FedaPay

$transaction = $user->chargeEscrow($transactionId, $prestataireId);

// Vous pouvez utiliser le résultat de la transaction pour la suite.
```

### 2. Libérer les fonds (Release Escrow)

Une fois la commande livrée ou le service validé, libérez les fonds pour que le prestataire les reçoive en effectuant un appel au service d'escrow :

```php
use Safepay\Services\EscrowService;

public function validerCommande(EscrowService $escrowService, $transactionId)
{
    $result = $escrowService->release($transactionId);

    if ($result['success']) {
        return response()->json(['message' => 'Les fonds ont bien été libérés pour le prestataire.']);
    }

    return response()->json(['error' => $result['message']], 400);
}
```

### 3. Consulter l'historique (Escrow Transactions)

Récupérer facilement l'historique des transactions liées à la plateforme par l'utilisateur connecté en passant par la relation Eloquent :

```php
$user = auth()->user();

// Liste des transactions
$transactions = $user->escrowTransactions()->latest()->get();
```

---

## 🪝 Webhook

Pour que la plateforme soit synchronisée avec l'état réel du paiement FedaPay de l'utilisateur, vous devez mettre en place un Webhook. Le package expose déjà une route prête à l'emploi.

L'endpoint à configurer est :
`POST https://votre-domaine.com/webhook/fedapay`

**Comment le configurer :**

1. Connectez-vous à votre [Dashboard FedaPay](https://live.fedapay.com/).
2. Allez dans **Webhooks > Créer un Webhook**.
3. Renseignez l'URL de destination : `https://votre-domaine.com/webhook/fedapay`.
4. Sélectionnez les événements pertinents (ex: `transaction.approved`, `transaction.canceled`).
5. Copiez la clé **Signature (Secret Key)** générée par FedaPay.
6. Collez-la dans votre `.env` à la variable `FEDAPAY_WEBHOOK_SECRET`.

**Attention** : Pensez à exclure l'URL `/webhook/fedapay` de la vérification de jeton CSRF de Laravel dans `app/Http/Middleware/VerifyCsrfToken.php` si nécessaire.

---

## 🧪 Tests

Si vous souhaitez contribuer ou simplement vérifier que le package fonctionne parfaitement avant déploiement, exécutez la commande PHPUnit intégrée :

```shell
./vendor/bin/phpunit
```

Tous les tests unitaires et fonctionnels du package seront lancés pour vérifier que le comportement du séquestre et la vérification des signatures du Webhook sont intègres.

---

## 🤝 Comment contribuer ?

Vous avez identifié un problème ou souhaitez apporter une amélioration ? Suivez ces quelques étapes :

1. **Fork** du dépôt.
2. Créez une nouvelle branche de fonctionnalité (`git checkout -b feature/AjoutSuperFonctionnalite`).
3. **Commit** de vos modifications (`git commit -m 'Ajout de la super fonctionnalité'`).
4. **Push** sur votre la branche (`git push origin feature/AjoutSuperFonctionnalite`).
5. Ouvez une **Pull Request**.

Si vous rencontrez des bugs ou que vous avez des idées de nouvelles fonctionnalités, n'hésitez pas à ouvrir une Issue !
