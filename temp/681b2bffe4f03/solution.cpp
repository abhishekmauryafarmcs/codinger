#include <iostream>
using namespace std;

int main() {
    // Your code here
    int n;
    cin >> n;
    
    int sum = 0, num;
    for (int i = 0; i < n; ++i) {
        cin >> num;
        sum += num;
    }
    cout << sum << endl;
    return 0;
}