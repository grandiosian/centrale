#!/usr/bin/env python3
"""Convertit les tableaux du programme de technologie (cycle 4) en référentiel de compétences pour la Centrale.

  python3 outils/referentiel_csv.py OST.csv SFC.csv CCRI.csv > public/passerelle/competences.json

Entrée : les trois tableaux « Programme C4 » (OST, SFC, CCRI) exportés en CSV (séparateur « ; », UTF-8, UTF-16 ou UTF-32).
Chaque tableau a une ligne d'en-tête « 5ème ; 4ème ; 3ème ; Connaissances », puis :
  « OST 1 - Décrire… »          compétence
  « OST 11 - Évolution des OST » sous-compétence
  ligne suivante                 capacités par niveau (« 1/ … 2/ … ») et connaissances

Sortie : JSON « referentiel.competences » v1. Codes stables :
  thème OST, compétence OST1, sous-compétence OST11, capacité OST11.5.2 (sous-compétence . niveau . numéro).
"""
import csv, io, json, re, sys

NIVEAUX = {'5ème': '5e', '4ème': '4e', '3ème': '3e'}
THEMES_TS = {'OST': 'T1', 'SFC': 'T2', 'CCRI': 'T3'}   # codes utilisés par TechnoStudio (T2C11 ≈ SFC11)
COULEURS = {'OST': '#30B34A', 'SFC': '#C45CCE', 'CCRI': '#E8B624'}   # thème 1 vert, thème 2 rose, thème 3 jaune orangé (mêmes que TechnoStudio)
COUPURE = re.compile(r'([a-zàâçéèêëîïôûùüÿœ])- ([a-zàâçéèêëîïôûùüÿœ])')


def lire(chemin):
    brut = open(chemin, 'rb').read()
    for enc in ('utf-32', 'utf-16', 'utf-8-sig'):
        try:
            texte = brut.decode(enc)
            if '\x00' not in texte:
                return list(csv.reader(io.StringIO(texte), delimiter=';'))
        except UnicodeDecodeError:
            pass
    raise SystemExit(f'Encodage non reconnu : {chemin}')


def propre(t):
    t = COUPURE.sub(r'\1\2', (t or '').replace(' ', ' ').replace('﻿', ''))
    t = re.sub(r'[ \t]+', ' ', t)
    return re.sub(r'\s*\n\s*', '\n', t).strip().strip('"').strip()


def capacites(cellule):
    """« 1/ A\n2/ B 3/C » → ['A', 'B', 'C'] ; texte sans numéro → [texte] ; vide → []."""
    t = propre(cellule)
    if not t:
        return []
    morceaux = re.split(r'(?:^|(?<=\s))(\d)\s*/\s*', t)
    if len(morceaux) == 1:
        return [t.replace('\n', ' ')]
    return [re.sub(r'\s+', ' ', morceaux[i + 1]).strip().rstrip(' ;') for i in range(1, len(morceaux) - 1, 2)]


def connaissances(cellule):
    """Puces « * » (et sous-puces « o ») ; la mention « Connaissances des deux autres thèmes » ouvre un 2e groupe."""
    propres, remobiliser = [], []
    cible = propres
    for ligne in propre(cellule).split('\n'):
        ligne = ligne.strip()
        if not ligne:
            continue
        if ligne.lower().startswith('connaissances des deux autres'):
            cible = remobiliser
            continue
        if re.match(r'^\*\s*', ligne):
            cible.append(re.sub(r'^\*\s*', '', ligne).rstrip(' ;.') )
        elif re.match(r'^o\s+', ligne) and cible:
            cible[-1] += ' — ' + re.sub(r'^o\s+', '', ligne).rstrip(' ;.')
        elif cible:
            cible[-1] += ' ' + ligne.rstrip(' ;.')
        else:
            cible.append(ligne.rstrip(' ;.'))
    return propres, remobiliser


def theme(chemin):
    lignes = lire(chemin)
    titre = propre(next(c for c in lignes[0] if c.strip()))
    code_t, _, intitule = titre.partition(' - ')
    code_t = code_t.strip()
    entete = next(l for l in lignes if any(c.strip() == '5ème' for c in l))
    col = {NIVEAUX.get(c.strip(), c.strip()): i for i, c in enumerate(entete) if c.strip() in NIVEAUX or c.strip() == 'Connaissances'}
    t = {'code': code_t, 'titre': intitule.strip(), 'technostudio': THEMES_TS.get(code_t), 'couleur': COULEURS.get(code_t, '#888888'), 'competences': []}
    comp, sous = None, None
    for l in lignes[lignes.index(entete) + 1:]:
        cellules = [propre(c) for c in l]
        premiere = next((c for c in cellules if c), '')
        m = re.match(rf'^{code_t}\s*(\d+)\s*-\s*(.+)$', premiere, re.S)
        if m and len(m.group(1)) == 1:
            comp = {'code': f'{code_t}{m.group(1)}', 'titre': propre(m.group(2)), 'sous': []}
            t['competences'].append(comp)
            continue
        if m and len(m.group(1)) == 2:
            sous = {'code': f'{code_t}{m.group(1)}', 'titre': propre(m.group(2)), 'capacites': [], 'connaissances': [], 'remobiliser': []}
            comp['sous'].append(sous)
            continue
        if sous is None or not premiere:
            continue
        # Certaines lignes sont décalées d'une colonne vers la gauche par rapport à l'en-tête (tableau SFC, 2e moitié)
        decalage = min(0, next(i for i, c in enumerate(cellules) if c) - col['5e'])
        cellule = lambda cle: l[col[cle] + decalage] if 0 <= col[cle] + decalage < len(l) else ''
        for niv in ('5e', '4e', '3e'):
            for n, texte in enumerate(capacites(cellule(niv)), 1):
                sous['capacites'].append({'code': f"{sous['code']}.{niv[0]}.{n}", 'niveau': niv, 'texte': texte})
        if 'Connaissances' in col:
            sous['connaissances'], sous['remobiliser'] = connaissances(cellule('Connaissances'))
    return t


def main(chemins):
    themes = [theme(c) for c in chemins]
    ordre = {'OST': 0, 'SFC': 1, 'CCRI': 2}
    themes.sort(key=lambda t: ordre.get(t['code'], 9))
    ref = {
        'format': 'referentiel.competences', 'version': 1, 'id': 'technologie-c4-2024',
        'titre': 'Technologie — cycle 4 (programme 2024)',
        'source': 'Programme de technologie du cycle 4, BO du 29 février 2024 (tableaux OST, SFC, CCRI).',
        'niveaux': ['5e', '4e', '3e'],
        'codes': 'Thème OST ; compétence OST1 ; sous-compétence OST11 ; capacité OST11.5.2 = sous-compétence OST11, niveau 5e, capacité n° 2. Les codes ne changent jamais.',
        'echelle': [
            {'niveau': 1, 'code': 'MI', 'libelle': 'Maîtrise insuffisante', 'couleur': '#d64035'},
            {'niveau': 2, 'code': 'MF', 'libelle': 'Maîtrise fragile', 'couleur': '#f0a030'},
            {'niveau': 3, 'code': 'MS', 'libelle': 'Maîtrise satisfaisante', 'couleur': '#7cb342'},
            {'niveau': 4, 'code': 'TBM', 'libelle': 'Très bonne maîtrise', 'couleur': '#2e7d32'},
        ],
        'correspondances': {'technostudio': {v: k for k, v in THEMES_TS.items()},
                            'note': 'TechnoStudio code T1 = OST, T2 = SFC, T3 = CCRI ; T2C11 ≈ sous-compétence SFC11.'},
        'themes': themes,
    }
    json.dump(ref, sys.stdout, ensure_ascii=False, indent=1)
    sys.stdout.write('\n')


if __name__ == '__main__':
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    main(sys.argv[1:])
