import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'core/theme/topkolik_theme.dart';
import 'core/localization/topkolik_localizations.dart';
import 'features/shell/topkolik_home_shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const TopkolikApp());
}

class TopkolikApp extends StatefulWidget {
  const TopkolikApp({super.key});

  @override
  State<TopkolikApp> createState() => _TopkolikAppState();
}

class _TopkolikAppState extends State<TopkolikApp> {
  Locale _currentLocale = const Locale('tr');

  void _changeLocale(Locale newLocale) {
    setState(() {
      _currentLocale = newLocale;
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Topkolik.com',
      debugShowCheckedModeBanner: false,
      theme: TopkolikTheme.lightTheme,
      darkTheme: TopkolikTheme.darkTheme,
      themeMode: ThemeMode.dark,
      locale: _currentLocale,
      supportedLocales: TopkolikLocalizations.supportedLocales,
      localizationsDelegates: const [
        TopkolikLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: TopkolikHomeShell(
        onLocaleChanged: _changeLocale,
      ),
    );
  }
}
