import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:topkolik_mobile/core/localization/topkolik_localizations.dart';
import 'package:topkolik_mobile/core/models/topkolik_domain_models.dart';
import 'package:topkolik_mobile/core/theme/topkolik_theme.dart';
import 'package:topkolik_mobile/features/match_arena/presentation/widgets/reaction_strip.dart';
import 'package:topkolik_mobile/features/match_arena/presentation/widgets/arena_poll_card.dart';
import 'package:topkolik_mobile/features/match_arena/presentation/widgets/segmented_tribune_view.dart';

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
    home: Scaffold(body: child),
  );
}

void main() {
  group('ReactionStrip', () {
    testWidgets('renders reaction counts and emits callback on tap', (WidgetTester tester) async {
      String? selectedType;
      await tester.pumpWidget(
        _wrapTestWidget(
          ReactionStrip(
            reactionTotals: const {'fire': 42, 'hype': 99},
            activeReaction: 'fire',
            onSelectReaction: (type) => selectedType = type,
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('42'), findsOneWidget);
      expect(find.text('99'), findsOneWidget);

      await tester.tap(find.text('99'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(selectedType, 'hype');
    });
  });

  group('ArenaPollCard', () {
    testWidgets('renders question, percentage, and allows voting', (WidgetTester tester) async {
      int? votedOptionId;
      const poll = ArenaPoll(
        id: 1,
        questionKey: 'mvp',
        questionText: 'Maçın En Değerli Oyuncusu Kim?',
        isClosed: false,
        options: [
          ArenaPollOption(id: 10, optionKey: 'icardi', label: 'Mauro Icardi', votesCount: 75),
          ArenaPollOption(id: 20, optionKey: 'dzeko', label: 'Edin Džeko', votesCount: 25),
        ],
      );

      await tester.pumpWidget(
        _wrapTestWidget(
          ArenaPollCard(
            poll: poll,
            onVote: (optId) => votedOptionId = optId,
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Maçın En Değerli Oyuncusu Kim?'), findsOneWidget);
      expect(find.text('Mauro Icardi'), findsOneWidget);
      expect(find.text('Edin Džeko'), findsOneWidget);
      expect(find.text('%75.0 (75)'), findsOneWidget);
      expect(find.text('%25.0 (25)'), findsOneWidget);

      await tester.tap(find.text('Mauro Icardi'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(votedOptionId, 10);
    });
  });

  group('SegmentedTribuneView', () {
    testWidgets('switches between tribunes and checks favorite club posting rule', (WidgetTester tester) async {
      final fixture = MatchFixture(
        id: 1,
        status: 'live',
        startsAt: DateTime.now(),
        homeTeamId: 1,
        homeTeamName: 'Galatasaray',
        awayTeamId: 2,
        awayTeamName: 'Fenerbahçe',
      );

      final comments = [
        ArenaComment(
          id: 1,
          userId: 1,
          authorName: 'Cimbomlu',
          section: 'home',
          body: 'Aslanlar sahada!',
          createdAt: DateTime.now(),
        ),
        ArenaComment(
          id: 2,
          userId: 2,
          authorName: 'Fenerli',
          section: 'away',
          body: 'Kazanacağız!',
          createdAt: DateTime.now(),
        ),
      ];

      await tester.pumpWidget(
        _wrapTestWidget(
          SegmentedTribuneView(
            fixture: fixture,
            comments: comments,
            postingEnabled: true,
            slowModeSeconds: 0,
            userFavoriteClub: 'Galatasaray',
            onPostComment: (section, body, parentId) {},
            onReportComment: (commentId, reason) {},
            onBlockUser: (_) {},
          ),
        ),
      );
      await tester.pump();

      // By default neutral tab is selected
      expect(find.text('Ortak Tartışma'), findsWidgets);

      // Switch to Home Tribune (Galatasaray)
      await tester.tap(find.text('Galatasaray Tribünü'));
      await tester.pump();
      expect(find.text('Aslanlar sahada!'), findsOneWidget);

      // Switch to Away Tribune (Fenerbahçe) -> user is Galatasaray fan, so cannot post
      await tester.tap(find.text('Fenerbahçe Tribünü'));
      await tester.pump();
      expect(find.text('Kazanacağız!'), findsOneWidget);
      // Restriction banner is visible
      expect(find.byIcon(Icons.lock_outline), findsOneWidget);
    });
  });
}
