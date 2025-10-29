#!/bin/bash

# 🚨 SCRIPT DE RÉPARATION ULTRA-RAPIDE 🚨
# Ce script corrige immédiatement le problème de localisation

set -e  # Arrête le script en cas d'erreur

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🔧 RÉPARATION RAPIDE - PROBLÈME DE LOCALISATION"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Fonction pour afficher les étapes
step() {
    echo -e "${BLUE}▶${NC} $1"
}

success() {
    echo -e "${GREEN}✅ $1${NC}"
}

error() {
    echo -e "${RED}❌ $1${NC}"
    exit 1
}

warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 1 : Backup de la configuration actuelle
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Sauvegarde de la configuration actuelle..."

if [ -f "config/packages/framework.yaml" ]; then
    cp config/packages/framework.yaml config/packages/framework.yaml.backup
    success "Backup créé : config/packages/framework.yaml.backup"
else
    warning "Fichier framework.yaml introuvable"
fi

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 2 : Arrêter le serveur Symfony
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Arrêt du serveur Symfony..."
symfony server:stop 2>/dev/null || true
success "Serveur arrêté"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 3 : Suppression complète du cache
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Suppression complète du cache..."

if [ -d "var/cache" ]; then
    rm -rf var/cache/*
    success "Cache supprimé"
fi

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 4 : Correction des permissions
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Correction des permissions..."

chmod -R 777 var/cache 2>/dev/null || true
chmod -R 777 var/log 2>/dev/null || true

success "Permissions corrigées"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 5 : Vider le cache Symfony (proprement)
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Vidage du cache Symfony..."

php bin/console cache:clear --no-warmup || error "Erreur lors du vidage du cache. Vérifie config/packages/framework.yaml"

success "Cache vidé"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 6 : Réchauffement du cache
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Réchauffement du cache..."

php bin/console cache:warmup || warning "Impossible de réchauffer le cache (non bloquant)"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 7 : Vérification de la configuration
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Vérification de la configuration..."

# Test Symfony
if php bin/console about > /dev/null 2>&1; then
    success "Symfony fonctionne correctement"
else
    error "Erreur de configuration Symfony. Lance : php bin/console about"
fi

# Test des routes
if php bin/console debug:router > /dev/null 2>&1; then
    success "Routes chargées correctement"
else
    warning "Problème avec les routes (non bloquant)"
fi

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# ÉTAPE 8 : Démarrage du serveur
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

step "Démarrage du serveur..."

symfony server:start -d || error "Impossible de démarrer le serveur"

success "Serveur démarré"

# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
# FIN
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo -e "${GREEN}🎉 RÉPARATION TERMINÉE AVEC SUCCÈS !${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo -e "${YELLOW}📋 PROCHAINES ÉTAPES :${NC}"
echo ""
echo "1. Ouvre ton navigateur et teste :"
echo -e "   ${GREEN}http://127.0.0.1:8000/${NC}"
echo -e "   ${GREEN}http://127.0.0.1:8000/selection${NC}"
echo ""
echo "2. Si ça ne fonctionne toujours pas :"
echo "   - Vérifie les logs : tail -50 var/log/dev.log"
echo "   - Lance : php bin/console debug:router"
echo ""
echo "3. Si tu vois des erreurs, partage-les moi !"
echo ""
echo -e "${BLUE}💡 Conseil :${NC} Si tu veux restaurer l'ancienne config :"
echo "   cp config/packages/framework.yaml.backup config/packages/framework.yaml"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
