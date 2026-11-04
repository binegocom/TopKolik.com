import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/localization/topkolik_localizations.dart';
import 'package:topkolik_mobile/core/models/topkolik_domain_models.dart';
import 'package:topkolik_mobile/core/theme/topkolik_theme.dart';
import 'package:topkolik_mobile/features/predictions/presentation/widgets/prediction_slip_card.dart';

Widget _wrapTestWidget(Widget child) {
  return MaterialApp(
    theme: TopkolikTheme.darkTheme,
    locale: const Locale('tr'),
    supportedLocales: TopkolikLocalizations.supportedLocales,
    localizationsDelegates: const [
      TopkolikLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    home: Scaffold(
      body: SingleChildScrollView(child: child),
    ),
  );
}

void main() {
  group('PredictionSlipCard', () {
    testWidgets('renders prediction pickers and increments score', (WidgetTester tester) async {
      final fixture = MatchFixture(
        id: 1,
        status: 'scheduled',
        startsAt: DateTime.now().add(const Duration(hours: 2)),
        homeTeamId: 1,
        homeTeamName: 'Galatasaray',
        awayTeamId: 2,
        awayTeamName: 'Fenerbahçe',
      );

      String? submittedOutcome;
      int? submittedHomeScore;
      int? submittedAwayScore;

      await tester.pumpWidget(
        _wrapTestWidget(
          PredictionSlipCard(
            fixture: fixture,
            isLocked: false,
            onSubmit: ({
              required String outcome,
              required int homeScore,
              required int awayScore,
              required String goalRange,
              required String firstScorer,
            }) {
              submittedOutcome = outcome;
              submittedHomeScore = homeScore;
              submittedAwayScore = awayScore;
            },
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Skor Tahmin Merkezi'), findsOneWidget);
      expect(find.text('Tahmini Kaydet'), findsOneWidget);

      // Tap on increment button for home score (starts at 2, becomes 3)
      final addIcons = find.byIcon(Icons.add_circle_outline);
      await tester.tap(addIcons.first);
      await tester.pump();

      expect(find.text('3'), findsOneWidget);

      // Submit prediction
      await tester.tap(find.text('Tahmini Kaydet'));
      await tester.pump();

      expect(submittedHomeScore, 3);
      expect(submittedAwayScore, 1);
      expect(submittedOutcome, 'home');
    });
  });
}
