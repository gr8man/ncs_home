# **Dokumentacja Klasy Combinator**

Klasa Combinator to zaawansowane narzędzie w PHP służące do generowania produktu kartezjańskiego (wszystkich możliwych kombinacji) z podanych danych. Narzędzie automatyzuje proces walidacji, uzupełniania danych domyślnych oraz dynamicznego tworzenia pól na podstawie szablonów.

## **Główne cechy**

* **Walidacja w stylu CodeIgniter 3**: Filtrowanie danych (pojedynczych wartości oraz elementów tablic) przed generowaniem wariacji.  
* **Obsługa grup**: Możliwość rozdzielania wyników do różnych kontenerów (np. left, right).  
* **Uniwersalne Akcje**: Jedna metoda action() obsługująca wiele typów operacji zdefiniowanych w konfiguracji.  
* **Dynamiczne szablony**: Automatyczne tworzenie lub nadpisywanie pól (np. nazw, opisów) na podstawie parametrów wariacji.

## **Konfiguracja Metod**

Konfiguracja znajduje się wewnątrz klasy w tablicie $config. Każdy klucz (np. drill, pin) definiuje:

1. rules: Reguły walidacji (np. required|integer|min\[5\]).  
2. defaults: Wartości przypisywane, jeśli klucz nie istnieje w danych wejściowych.  
3. templates: Definicje pól generowanych dynamicznie. Kluczem jest nazwa pola docelowego, a wartością szablon z tagami {klucz}.

## **Dostępne Reguły Walidacji**

| Reguła | Opis | Przykład |
| :---- | :---- | :---- |
| required | Pole musi posiadać wartość i nie może być puste. | required |
| numeric | Wartość musi być liczbą (string lub int). | numeric |
| integer | Wartość musi być liczbą całkowitą. | integer |
| decimal | Wartość musi być liczbą zmiennoprzecinkową. | decimal |
| natural | Liczby naturalne (0, 1, 2...). | natural |
| natural\_no\_zero | Liczby naturalne większe od zera (1, 2...). | natural\_no\_zero |
| min\[x\] | Minimalna wartość liczbowa. | min\[10\] |
| max\[x\] | Maksymalna wartość liczbowa. | max\[100\] |
| val\[a,b\] | Wartość musi znajdować się na liście (rozdzielonej przecinkami). | val\[left,right\] |

## **API i Metody**

### **group(array $groups): self**

Ustawia grupy docelowe dla wyników najbliższej akcji.

### **action(string $method, array $data): self**

Wykonuje proces generowania dla metody zdefiniowanej w $config (np. drill, pin). Zastępuje potrzebę tworzenia osobnych metod dla każdej akcji.

### **out(string|array|null $filter \= null): array**

Zwraca dane z bufora. Można filtrować według nazwy grupy (string) lub zestawu grup (array).

### **reset(?string $groupName \= null): self**

Czyści pamięć podręczną dla jednej lub wszystkich grup.

## **Przykłady Użycia**

### **Przykład 1: Uniwersalna metoda action()**

$comb \= new Combinator();

// Wywołanie akcji 'drill' dla dwóch grup jednocześnie  
$comb-\>group(\['left', 'right'\])-\>action('drill', \[  
    'name' \=\> 'Wiertło',  
    'fn'   \=\> \[10, 20\],  
    'nf'   \=\> \['left', 'wrong\_val'\] // 'wrong\_val' zostanie odrzucone przez regułę val\[\]  
\]);

$res \= $comb-\>out('left');

### **Przykład 2: Dynamiczne szablony wielu pól (Metoda Pin)**

Akcja pin generuje dwa dodatkowe pola: custom\_label oraz description.

$comb \= new Combinator();

$comb-\>group(\['strefa\_1'\])-\>action('pin', \[  
    'id'   \=\> 105,  
    'code' \=\> \[2000, 3000\],  
    'mode' \=\> 'auto'  
\]);

// Wynik zawiera pola: id, code, mode oraz wygenerowane: custom\_label, description.  
print\_r($comb-\>out('strefa\_1'));

### **Przykład 3: Czyszczenie i łączenie danych**

$comb \= new Combinator();  
$comb-\>group(\['A'\])-\>action('drill', $dane1);  
$comb-\>group(\['A'\])-\>action('pin', $dane2); // Dane zostaną dopisane do grupy A

$comb-\>reset('A'); // Czyści wszystkie wyniki w grupie A  
