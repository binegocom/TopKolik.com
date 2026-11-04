import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/localization/topkolik_localizations.dart';

void main() {
  group('TopkolikLocalizations', () {
    test('supports all 5 languages per ADR-004', () {
      final supportedCodes = TopkolikLocalizations.supportedLocales.map((l) => l.languageCode).toList();
      expect(supportedCodes, containsAll(['tr', 'en', 'de', 'fr', 'it']));
    });

    test('Turkish locale contains critical football fan tokens', () {
      final l10nTr = TopkolikLocalizations(const Locale('tr'));
      expect(l10nTr.translate('app_title'), 'Topkolik.com');
      expect(l10nTr.translate('tab_arena'), 'Maç Arenası');
      expect(l10nTr.translate('tab_predictions'), 'Tahminler');
      expect(l10nTr.translate('tab_rankings'), 'Taraftar Ligi');
      expect(l10nTr.translate('tribune_home'), 'Ev Sahibi Tribünü');
      expect(l10nTr.translate('tribune_away'), 'Deplasman Tribünü');
      expect(l10nTr.translate('tribune_neutral'), 'Ortak Tartışma');
    });

    test('English locale provides full coverage', () {
      final l10nEn = TopkolikLocalizations(const Locale('en'));
      expect(l10nEn.translate('tab_arena'), 'Match Arena');
      expect(l10nEn.translate('tab_predictions'), 'Predictions');
      expect(l10nEn.translate('tab_rankings'), 'Fan League');
    });

    test('German locale preserves source translation', () {
      final l10nDe = TopkolikLocalizations(const Locale('de'));
      expect(l10nDe.translate('tab_arena'), 'Spiel-Arena');
      expect(l10nDe.translate('tab_predictions'), 'Tippspiel');
    });
  });
}
